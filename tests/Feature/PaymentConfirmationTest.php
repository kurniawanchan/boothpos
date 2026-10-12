<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 031-optional-payment-proof (US2/US3) — menambah / mengubah / mengganti KONFIRMASI
 * (bukti, referensi, catatan) sebuah pembayaran non-tunai belakangan:
 * PATCH /orders/{order}/payments/{payment}/confirmation (dan padanannya untuk
 * pre-order di bawah). Yang dikunci di sini: siapa boleh, kapan ditolak, bukti lama
 * tidak dihapus saat diganti, jejak audit, dan — paling penting — tak ada uang,
 * status, total, atau kas shift yang berubah.
 */
class PaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $recorder;

    private ProductVariant $variant;

    private PaymentChannel $channel;

    private int $skuCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->recorder = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $product = Product::factory()->create([
            'home_outlet_id' => Outlet::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create(['sku' => 'CNF0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 50]);
        $this->channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
    }

    private function as(User $user): static
    {
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** Penjualan 50.000 dengan SATU pembayaran QRIS tanpa bukti, dicatat oleh $this->recorder. */
    private function sale(?User $cashier = null, ?array $payments = null): array
    {
        $cashier ??= $this->recorder;
        $session = CashierSession::factory()->create([
            'user_id' => $cashier->id, 'status' => 'open',
        ]);
        $this->as($cashier);

        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
            'payments' => $payments ?? [['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 50000]],
        ])->assertCreated()->json();

        return ['order' => $order, 'payment_id' => $order['payments'][0]['id']];
    }

    private function confirm(int $orderId, int $paymentId, array $body)
    {
        return $this->patchJson("/api/v1/orders/{$orderId}/payments/{$paymentId}/confirmation", $body);
    }

    private function uploadProof(): string
    {
        return $this->postJson('/api/v1/payment-proofs', [
            'file' => UploadedFile::fake()->image('bukti.jpg'), 'captured_via' => 'upload',
        ])->assertCreated()->json('proof_token');
    }

    // --- siapa boleh ---------------------------------------------------------------

    public function test_owner_admin_and_the_recording_cashier_may_add_a_confirmation(): void
    {
        foreach ([$this->userWithRole('owner'), $this->userWithRole('admin'), $this->recorder] as $user) {
            $s = $this->sale();
            $this->as($user);

            $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'TRX-'.$user->id])
                ->assertOk()->assertJsonPath('payments.0.reference', 'TRX-'.$user->id);
        }
    }

    public function test_another_cashier_and_other_roles_are_refused_with_403(): void
    {
        $s = $this->sale();

        foreach ([$this->userWithRole('cashier'), $this->userWithRole('inventory')] as $user) {
            $this->as($user);
            $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'X'])->assertForbidden();
        }

        $this->assertNull(Payment::find($s['payment_id'])->reference);
    }

    public function test_a_payment_with_no_recorded_user_is_changeable_by_owner_only_not_by_a_cashier(): void
    {
        $s = $this->sale();
        Payment::whereKey($s['payment_id'])->update(['recorded_by' => null]);

        $this->as($this->recorder)->confirm($s['order']['id'], $s['payment_id'], ['notes' => 'x'])->assertForbidden();
        $this->as($this->userWithRole('owner'))->confirm($s['order']['id'], $s['payment_id'], ['notes' => 'x'])->assertOk();
    }

    // --- apa yang bisa disimpan ----------------------------------------------------

    public function test_proof_reference_and_notes_can_be_added_together(): void
    {
        $s = $this->sale();
        $token = $this->uploadProof();

        $response = $this->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $token, 'reference' => 'TRX-9', 'notes' => 'Transfer dari BCA'])->assertOk();

        $entry = $response->json('payments.0');
        $this->assertSame('TRX-9', $entry['reference']);
        $this->assertSame('Transfer dari BCA', $entry['notes']);
        $this->assertTrue($entry['has_proof']);
        $this->assertNotNull($entry['proof_id']);
        $this->assertDatabaseHas('payment_proofs', ['proof_token' => $token, 'payment_id' => $s['payment_id']]);
    }

    public function test_each_of_the_three_parts_can_be_saved_on_its_own(): void
    {
        $a = $this->sale();
        $this->confirm($a['order']['id'], $a['payment_id'], ['reference' => 'ONLY-REF'])->assertOk()->assertJsonPath('payments.0.has_proof', false);

        $b = $this->sale();
        $this->confirm($b['order']['id'], $b['payment_id'], ['notes' => 'Hanya catatan'])->assertOk()->assertJsonPath('payments.0.notes', 'Hanya catatan');

        $c = $this->sale();
        $this->confirm($c['order']['id'], $c['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk()->assertJsonPath('payments.0.has_proof', true);
    }

    public function test_an_empty_request_is_refused(): void
    {
        $s = $this->sale();

        $this->confirm($s['order']['id'], $s['payment_id'], [])->assertStatus(422)->assertJsonValidationErrors('confirmation');
    }

    public function test_too_long_reference_or_notes_are_refused(): void
    {
        $s = $this->sale();

        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => str_repeat('x', 101)])->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->confirm($s['order']['id'], $s['payment_id'], ['notes' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonValidationErrors('notes');
    }

    public function test_an_unknown_or_already_used_proof_token_is_refused_and_nothing_changes(): void
    {
        $first = $this->sale();
        $token = $this->uploadProof();
        $this->confirm($first['order']['id'], $first['payment_id'], ['proof_token' => $token])->assertOk();

        $second = $this->sale();
        $this->confirm($second['order']['id'], $second['payment_id'], ['proof_token' => $token, 'reference' => 'AKAN-BATAL'])->assertStatus(422);
        $this->confirm($second['order']['id'], $second['payment_id'], ['proof_token' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422);

        $this->assertNull(Payment::find($second['payment_id'])->reference);
        $this->assertSame(0, PaymentProof::where('payment_id', $second['payment_id'])->count());
    }

    public function test_a_confirmation_cannot_be_added_to_a_cash_payment(): void
    {
        $s = $this->sale(payments: [['method' => 'cash', 'amount' => 50000]]);

        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'X'])->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_a_payment_of_another_sale_is_not_found(): void
    {
        $one = $this->sale();
        $two = $this->sale();

        $this->confirm($one['order']['id'], $two['payment_id'], ['reference' => 'X'])->assertNotFound();
    }

    public function test_a_voided_sale_cannot_be_changed(): void
    {
        $s = $this->sale();
        Order::whereKey($s['order']['id'])->update(['status' => 'voided']);

        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'X'])->assertStatus(409);
        $this->assertNull(Payment::find($s['payment_id'])->reference);
    }

    public function test_a_payment_of_the_other_data_mode_is_not_found(): void
    {
        $s = $this->sale();
        // pindahkan baris ke mode DEMO sementara aplikasi aktif di mode LIVE
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $s['order']['id'])->update(['data_mode' => 'demo']);
        \Illuminate\Support\Facades\DB::table('payments')->where('id', $s['payment_id'])->update(['data_mode' => 'demo']);

        $this->as($this->userWithRole('owner'))->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'X'])->assertNotFound();
    }

    // --- mengubah dan mengganti ----------------------------------------------------

    public function test_reference_and_notes_can_be_edited_and_one_part_cleared_while_another_remains(): void
    {
        $s = $this->sale();
        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'LAMA', 'notes' => 'catatan'])->assertOk();

        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'BARU'])->assertOk()->assertJsonPath('payments.0.reference', 'BARU');
        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => null])->assertOk()->assertJsonPath('payments.0.reference', null)->assertJsonPath('payments.0.notes', 'catatan');
    }

    public function test_an_edit_that_would_leave_the_confirmation_completely_empty_is_refused(): void
    {
        $s = $this->sale();
        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'SATU-SATUNYA'])->assertOk();

        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => null])->assertStatus(422)->assertJsonValidationErrors('confirmation');

        $this->assertSame('SATU-SATUNYA', Payment::find($s['payment_id'])->reference);
    }

    public function test_replacing_a_proof_keeps_the_old_file_and_marks_it_superseded(): void
    {
        $s = $this->sale();
        $this->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();
        $old = PaymentProof::where('payment_id', $s['payment_id'])->firstOrFail();

        $response = $this->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();

        $old->refresh();
        $new = PaymentProof::where('payment_id', $s['payment_id'])->whereNull('superseded_at')->firstOrFail();
        $this->assertNotNull($old->superseded_at);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($new->id, $response->json('payments.0.proof_id'));
        Storage::disk('local')->assertExists($old->file_path);
        Storage::disk('local')->assertExists($new->file_path);
    }

    // --- audit ---------------------------------------------------------------------

    public function test_every_change_is_written_to_the_activity_log_with_old_and_new_values(): void
    {
        $s = $this->sale();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'A', 'notes' => 'n1'])->assertOk();
        $firstProof = $this->uploadProof();
        $this->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'B', 'proof_token' => $firstProof])->assertOk();
        $proof = PaymentProof::where('proof_token', $firstProof)->firstOrFail();

        $logs = ActivityLog::where('action', 'payment_confirmation_updated')->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame($owner->id, $logs[1]->user_id);
        $this->assertSame('Order', $logs[1]->entity_type);
        $this->assertSame($s['order']['id'], (int) $logs[1]->entity_id);
        $this->assertSame('A', $logs[1]->old_values['reference']);
        $this->assertSame('B', $logs[1]->new_values['reference']);
        $this->assertNull($logs[1]->old_values['proof_id']);
        $this->assertSame($proof->id, $logs[1]->new_values['proof_id']);
        $this->assertSame($s['payment_id'], $logs[1]->new_values['payment_id']);
    }

    public function test_a_refused_change_leaves_no_activity_log_row(): void
    {
        $s = $this->sale();
        $before = ActivityLog::count();

        $this->as($this->userWithRole('cashier'))->confirm($s['order']['id'], $s['payment_id'], ['reference' => 'X'])->assertForbidden();
        $this->as($this->recorder)->confirm($s['order']['id'], $s['payment_id'], [])->assertStatus(422);

        $this->assertSame($before, ActivityLog::count());
    }

    // --- tak ada uang yang berubah -------------------------------------------------

    public function test_a_confirmation_never_changes_money_status_totals_or_shift_cash(): void
    {
        $s = $this->sale();
        $order = Order::findOrFail($s['order']['id']);
        $paymentBefore = Payment::find($s['payment_id'])->only(['amount', 'method', 'channel_id', 'purpose', 'verification', 'paid_at', 'session_id', 'recorded_by']);
        $orderBefore = $order->only(['total_amount', 'paid_amount', 'change_amount', 'status', 'subtotal', 'discount_amount']);
        $summaryBefore = $this->as($this->recorder)->getJson("/api/v1/orders/{$order->id}")->json('payment_summary');
        $session = CashierSession::findOrFail($order->session_id);
        $closeBefore = $this->as($this->recorder)->getJson("/api/v1/sessions/{$session->id}/summary")->json();

        $this->confirm($order->id, $s['payment_id'], ['proof_token' => $this->uploadProof(), 'reference' => 'TRX-1', 'notes' => 'ok'])->assertOk();
        $this->confirm($order->id, $s['payment_id'], ['proof_token' => $this->uploadProof(), 'reference' => 'TRX-2'])->assertOk();

        $this->assertEquals($paymentBefore, Payment::find($s['payment_id'])->only(array_keys($paymentBefore)));
        $this->assertEquals($orderBefore, $order->fresh()->only(array_keys($orderBefore)));
        $this->assertEquals($summaryBefore, $this->getJson("/api/v1/orders/{$order->id}")->json('payment_summary'));
        $this->assertEquals($closeBefore, $this->getJson("/api/v1/sessions/{$session->id}/summary")->json());
    }

    public function test_deleting_the_payment_still_removes_every_proof_row_and_file_including_superseded_ones(): void
    {
        $s = $this->sale();
        $this->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();
        $this->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();
        $paths = PaymentProof::where('payment_id', $s['payment_id'])->pluck('file_path')->all();
        $this->assertCount(2, $paths);

        $this->as($this->userWithRole('owner'))->deleteJson("/api/v1/orders/{$s['order']['id']}/payments/{$s['payment_id']}")->assertOk();

        $this->assertSame(0, PaymentProof::where('file_path', $paths[0])->orWhere('file_path', $paths[1])->count());
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    // --- membuka bukti + flag di payload (FR-014) ----------------------------------

    public function test_the_recorder_can_open_a_proof_the_owner_added_but_another_cashier_cannot(): void
    {
        $s = $this->sale();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();
        $proof = PaymentProof::where('payment_id', $s['payment_id'])->firstOrFail();

        $this->as($owner)->get("/api/v1/payment-proofs/{$proof->id}/file")->assertOk();
        $this->as($this->recorder)->get("/api/v1/payment-proofs/{$proof->id}/file")->assertOk();
        $this->as($this->userWithRole('cashier'))->get("/api/v1/payment-proofs/{$proof->id}/file")->assertForbidden();
    }

    public function test_a_superseded_proof_is_closed_to_the_recorder_but_open_to_the_owner(): void
    {
        $s = $this->sale();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();
        $old = PaymentProof::where('payment_id', $s['payment_id'])->firstOrFail();
        $this->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof()])->assertOk();

        $this->as($this->recorder)->get("/api/v1/payment-proofs/{$old->id}/file")->assertForbidden();
        $this->as($owner)->get("/api/v1/payment-proofs/{$old->id}/file")->assertOk();
    }

    public function test_the_payload_flags_follow_who_is_asking_and_the_state_of_the_sale(): void
    {
        $s = $this->sale();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->confirm($s['order']['id'], $s['payment_id'], ['proof_token' => $this->uploadProof(), 'notes' => 'Catatan'])->assertOk();
        $other = $this->userWithRole('cashier');

        $asOwner = $this->as($owner)->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $asRecorder = $this->as($this->recorder)->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $asOther = $this->as($other)->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');

        $this->assertSame('Catatan', $asOther['notes']);
        $this->assertTrue($asOther['has_proof']);
        foreach ([$asOwner, $asRecorder] as $entry) {
            $this->assertTrue($entry['can_edit_confirmation']);
            $this->assertTrue($entry['can_view_proof']);
            $this->assertNotNull($entry['proof_id']);
        }
        $this->assertFalse($asOther['can_edit_confirmation']);
        $this->assertFalse($asOther['can_view_proof']);

        Order::whereKey($s['order']['id'])->update(['status' => 'voided']);
        $voided = $this->as($owner)->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $this->assertFalse($voided['can_edit_confirmation']);
        $this->assertTrue($voided['can_view_proof']);
    }

    public function test_a_cash_payment_never_offers_confirmation_editing(): void
    {
        $s = $this->sale(payments: [['method' => 'cash', 'amount' => 50000]]);

        $entry = $this->as($this->userWithRole('owner'))->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');

        $this->assertFalse($entry['can_edit_confirmation']);
        $this->assertFalse($entry['has_proof']);
    }
}
