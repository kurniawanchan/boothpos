<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 032-mark-payment-verified — memverifikasi SATU pembayaran non-tunai:
 * POST /orders/{order}/payments/{payment}/verify dan padanannya untuk pre-order.
 * Dikunci di sini: siapa boleh (pencatat TIDAK boleh), matriks keadaan (satu arah, final),
 * kapan ditolak, klik ganda hanya sekali, dan — paling penting — tak ada uang, status,
 * total, atau kas shift yang berubah. Tidak ada jalur untuk membatalkan verifikasi.
 */
class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $recorder;

    private ProductVariant $variant;

    private PaymentChannel $channel;

    private int $skuCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create(['sku' => 'VRF0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 100]);
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

    /** Penjualan 50.000 dengan pembayaran non-tunai (default: satu QRIS 50.000) dicatat oleh $cashier. */
    private function sale(?User $cashier = null, ?array $payments = null): array
    {
        $cashier ??= $this->recorder;
        $session = CashierSession::factory()->create([
            'event_id' => Event::factory()->create(['status' => 'active'])->id, 'user_id' => $cashier->id, 'status' => 'open',
        ]);
        $this->as($cashier);

        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
            'payments' => $payments ?? [['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 50000]],
        ])->assertCreated()->json();

        return ['order' => $order, 'payment_ids' => collect($order['payments'])->pluck('id')->all(), 'payment_id' => $order['payments'][0]['id']];
    }

    private function verify(int $orderId, int $paymentId)
    {
        return $this->postJson("/api/v1/orders/{$orderId}/payments/{$paymentId}/verify");
    }

    private function paymentState(int $orderId): ?string
    {
        $rows = $this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions');

        return collect($rows)->firstWhere('id', $orderId)['payment_state'] ?? null;
    }

    // --- siapa boleh ---------------------------------------------------------------

    public function test_owner_admin_and_another_cashier_may_verify_but_the_recording_cashier_may_not(): void
    {
        foreach ([$this->userWithRole('owner'), $this->userWithRole('admin'), $this->userWithRole('cashier')] as $user) {
            $s = $this->sale();
            $this->as($user);

            $this->verify($s['order']['id'], $s['payment_id'])->assertOk()->assertJsonPath('payments.0.verification', 'verified');
        }

        $s = $this->sale();
        $this->as($this->recorder)->verify($s['order']['id'], $s['payment_id'])->assertForbidden();
        $this->assertSame('pending', Payment::find($s['payment_id'])->verification);
        $this->assertNull(Payment::find($s['payment_id'])->verified_by);
    }

    public function test_an_owner_may_verify_a_payment_they_recorded_themselves(): void
    {
        $owner = $this->userWithRole('owner');
        $s = $this->sale($owner);

        $this->as($owner)->verify($s['order']['id'], $s['payment_id'])->assertOk();
    }

    public function test_a_payment_with_no_recorded_user_can_be_verified_by_any_cashier(): void
    {
        $s = $this->sale();
        Payment::whereKey($s['payment_id'])->update(['recorded_by' => null]);

        $this->as($this->recorder)->verify($s['order']['id'], $s['payment_id'])->assertOk();
    }

    // --- matriks keadaan -----------------------------------------------------------

    public function test_a_pending_payment_becomes_verified_with_the_verifier_and_time_recorded(): void
    {
        $s = $this->sale();
        $verifier = $this->userWithRole('admin');

        $this->as($verifier)->verify($s['order']['id'], $s['payment_id'])->assertOk();

        $payment = Payment::find($s['payment_id']);
        $this->assertSame('verified', $payment->verification);
        $this->assertSame($verifier->id, (int) $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
    }

    public function test_verifying_again_is_refused_and_changes_nothing_more(): void
    {
        $s = $this->sale();
        $first = $this->userWithRole('owner');
        $this->as($first)->verify($s['order']['id'], $s['payment_id'])->assertOk();
        $stamp = Payment::find($s['payment_id'])->verified_at;
        $logs = ActivityLog::where('action', 'payment_verified')->count();

        $this->as($this->userWithRole('admin'))->verify($s['order']['id'], $s['payment_id'])->assertStatus(409);

        $this->assertEquals($stamp, Payment::find($s['payment_id'])->verified_at);
        $this->assertSame($first->id, (int) Payment::find($s['payment_id'])->verified_by);
        $this->assertSame($logs, ActivityLog::where('action', 'payment_verified')->count());
    }

    public function test_a_double_click_verifies_once_with_one_verifier_and_one_log_row(): void
    {
        $s = $this->sale();
        $this->as($this->userWithRole('owner'));

        $this->verify($s['order']['id'], $s['payment_id'])->assertOk();
        $this->verify($s['order']['id'], $s['payment_id'])->assertStatus(409);

        $this->assertSame(1, ActivityLog::where('action', 'payment_verified')->count());
    }

    public function test_a_rejected_payment_cannot_be_verified(): void
    {
        $s = $this->sale();
        Payment::whereKey($s['payment_id'])->update(['verification' => 'rejected']);

        $this->as($this->userWithRole('owner'))->verify($s['order']['id'], $s['payment_id'])->assertStatus(409);
        $this->assertSame('rejected', Payment::find($s['payment_id'])->verification);
    }

    public function test_a_cash_payment_cannot_be_verified(): void
    {
        $s = $this->sale(payments: [['method' => 'cash', 'amount' => 50000]]);

        $this->as($this->userWithRole('owner'))->verify($s['order']['id'], $s['payment_id'])->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_a_voided_sale_cannot_have_payments_verified(): void
    {
        $s = $this->sale();
        Order::whereKey($s['order']['id'])->update(['status' => 'voided']);

        $this->as($this->userWithRole('owner'))->verify($s['order']['id'], $s['payment_id'])->assertStatus(409);
        $this->assertSame('pending', Payment::find($s['payment_id'])->verification);
    }

    public function test_a_payment_of_another_sale_or_data_mode_is_not_found(): void
    {
        $one = $this->sale();
        $two = $this->sale();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->verify($one['order']['id'], $two['payment_id'])->assertNotFound();

        DB::table('orders')->where('id', $two['order']['id'])->update(['data_mode' => 'demo']);
        DB::table('payments')->where('id', $two['payment_id'])->update(['data_mode' => 'demo']);
        $this->verify($two['order']['id'], $two['payment_id'])->assertNotFound();
    }

    public function test_the_sales_badge_clears_only_when_the_last_pending_payment_of_a_split_sale_is_verified(): void
    {
        $s = $this->sale(payments: [
            ['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 20000],
            ['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 30000],
        ]);
        $owner = $this->userWithRole('owner');
        $this->as($owner);
        $this->assertSame('pending', $this->paymentState($s['order']['id']));

        $this->verify($s['order']['id'], $s['payment_ids'][0])->assertOk();
        $this->assertSame('pending', $this->paymentState($s['order']['id']));

        $this->verify($s['order']['id'], $s['payment_ids'][1])->assertOk();
        $this->assertSame('verified', $this->paymentState($s['order']['id']));
    }

    // --- tak ada uang yang berubah -------------------------------------------------

    public function test_verification_never_changes_money_status_totals_or_expected_cash(): void
    {
        $s = $this->sale();
        $order = Order::findOrFail($s['order']['id']);
        $paymentBefore = Payment::find($s['payment_id'])->only(['amount', 'method', 'channel_id', 'purpose', 'paid_at', 'session_id', 'recorded_by', 'reference', 'notes']);
        $orderBefore = $order->only(['total_amount', 'paid_amount', 'change_amount', 'status', 'subtotal', 'discount_amount']);
        $this->as($this->recorder);
        $summaryBefore = $this->getJson("/api/v1/orders/{$order->id}")->json('payment_summary');
        $shiftBefore = $this->getJson("/api/v1/sessions/{$order->session_id}/summary")->json();

        $this->as($this->userWithRole('owner'))->verify($order->id, $s['payment_id'])->assertOk();

        $this->assertEquals($paymentBefore, Payment::find($s['payment_id'])->only(array_keys($paymentBefore)));
        $this->assertEquals($orderBefore, $order->fresh()->only(array_keys($orderBefore)));
        $this->assertEquals($summaryBefore, $this->getJson("/api/v1/orders/{$order->id}")->json('payment_summary'));

        // Ringkasan shift: angka penjualan, jumlah order, dan kas (tunai) TIDAK berubah.
        $shiftAfter = $this->getJson("/api/v1/sessions/{$order->session_id}/summary")->json();
        foreach (['order_count', 'total_sales', 'opening_cash_entries'] as $key) {
            $this->assertEquals($shiftBefore[$key], $shiftAfter[$key], $key);
        }
        $this->assertEmpty(collect($shiftAfter['by_method'])->where('method', 'cash')->all());
    }

    /**
     * Rincian per metode di ringkasan shift SEJAK AWAL hanya menghitung pembayaran `verified`
     * (CashierSessionController::summary) — non-tunai baru muncul di sana setelah diverifikasi.
     * Itu aturan yang sudah ada, bukan efek samping tak sengaja: memverifikasi QRIS memasukkannya
     * ke rincian itu. Kas yang diharapkan (tunai saja) tidak ikut berubah.
     */
    public function test_a_verified_non_cash_payment_enters_the_shift_by_method_breakdown_only_once_verified(): void
    {
        $s = $this->sale();
        $sessionId = Order::findOrFail($s['order']['id'])->session_id;
        $this->as($this->recorder);
        $before = collect($this->getJson("/api/v1/sessions/{$sessionId}/summary")->json('by_method'));
        $this->assertNull($before->firstWhere('method', 'qr_ewallet'));

        $this->as($this->userWithRole('owner'))->verify($s['order']['id'], $s['payment_id'])->assertOk();

        $after = collect($this->as($this->recorder)->getJson("/api/v1/sessions/{$sessionId}/summary")->json('by_method'));
        $row = $after->firstWhere('method', 'qr_ewallet');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['count']);
        $this->assertEquals(50000, (float) $row['amount']);
    }

    // --- tak ada jalur membatalkan verifikasi (keputusan produk: final) ------------

    public function test_there_is_no_way_to_undo_a_verification(): void
    {
        $s = $this->sale();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->verify($s['order']['id'], $s['payment_id'])->assertOk();

        foreach (['unverify', 'verification', 'unverify-payment'] as $suffix) {
            foreach (['patch', 'delete', 'post', 'put'] as $verb) {
                $this->{$verb.'Json'}("/api/v1/orders/{$s['order']['id']}/payments/{$s['payment_id']}/{$suffix}")->assertStatus(404);
            }
        }
        $this->patchJson("/api/v1/orders/{$s['order']['id']}/payments/{$s['payment_id']}", ['verification' => 'pending'])->assertStatus(405);

        $this->assertSame('verified', Payment::find($s['payment_id'])->verification);
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('unverify', $route->uri());
        }
    }

    // =================================================================================
    // Jalur pre-order: POST /preorders/{preorder}/payments/{payment}/verify
    // =================================================================================

    /** Pre-order 200.000 dengan SATU pembayaran QRIS 50.000, dicatat oleh $cashier. */
    private function preorder(?User $cashier = null, string $method = 'qr_ewallet'): array
    {
        $cashier ??= $this->recorder;
        $preVariant = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ])->variants()->create(['sku' => 'PRV'.str_pad((string) (++$this->skuCounter), 5, '0', STR_PAD_LEFT), 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);

        $this->as($cashier);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => Customer::factory()->create()->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $preVariant->id, 'qty' => 2]],
        ])->assertCreated()->json();

        $payload = ['method' => $method, 'amount' => 50000, 'purpose' => 'down_payment'];
        if ($method !== 'cash') {
            $payload['channel_id'] = $this->channel->id;
        }
        $paid = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", $payload)->assertCreated()->json();

        return ['preorder' => $paid, 'payment_id' => $paid['payments'][0]['id']];
    }

    private function verifyPreorder(int $preorderId, int $paymentId)
    {
        return $this->postJson("/api/v1/preorders/{$preorderId}/payments/{$paymentId}/verify");
    }

    public function test_preorder_owner_admin_and_another_cashier_may_verify_but_the_recorder_may_not(): void
    {
        foreach ([$this->userWithRole('owner'), $this->userWithRole('admin'), $this->userWithRole('cashier')] as $user) {
            $p = $this->preorder();
            $this->as($user);
            $this->verifyPreorder($p['preorder']['id'], $p['payment_id'])->assertOk()->assertJsonPath('payments.0.verification', 'verified');
        }

        $p = $this->preorder();
        $this->as($this->recorder)->verifyPreorder($p['preorder']['id'], $p['payment_id'])->assertForbidden();
        $this->assertSame('pending', Payment::find($p['payment_id'])->verification);
    }

    public function test_verifying_a_preorder_payment_never_changes_paid_amount_status_or_outstanding(): void
    {
        $p = $this->preorder();
        $before = Preorder::findOrFail($p['preorder']['id'])->only(['paid_amount', 'status', 'total_amount', 'discount']);
        $this->as($this->recorder);
        $summaryBefore = $this->getJson("/api/v1/preorders/{$p['preorder']['id']}")->json('payment_summary');

        $this->as($this->userWithRole('owner'))->verifyPreorder($p['preorder']['id'], $p['payment_id'])->assertOk();

        $this->assertEquals($before, Preorder::findOrFail($p['preorder']['id'])->only(array_keys($before)));
        $this->assertSame('dp_paid', Preorder::findOrFail($p['preorder']['id'])->status);
        $this->assertEquals($summaryBefore, $this->getJson("/api/v1/preorders/{$p['preorder']['id']}")->json('payment_summary'));
    }

    public function test_a_cancelled_preorder_is_refused_but_a_handed_over_one_can_be_verified(): void
    {
        $cancelled = $this->preorder();
        Preorder::whereKey($cancelled['preorder']['id'])->update(['status' => 'cancelled']);
        $owner = $this->userWithRole('owner');
        $this->as($owner)->verifyPreorder($cancelled['preorder']['id'], $cancelled['payment_id'])->assertStatus(409);

        $done = $this->preorder();
        Preorder::whereKey($done['preorder']['id'])->update(['status' => 'handed_over']);
        $this->as($owner)->verifyPreorder($done['preorder']['id'], $done['payment_id'])->assertOk();
    }

    public function test_preorder_foreign_payment_cash_and_repeat_are_refused(): void
    {
        $one = $this->preorder();
        $two = $this->preorder();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->verifyPreorder($one['preorder']['id'], $two['payment_id'])->assertNotFound();

        $cash = $this->preorder(method: 'cash');
        $this->as($owner)->verifyPreorder($cash['preorder']['id'], $cash['payment_id'])->assertStatus(422)->assertJsonValidationErrors('method');

        $this->verifyPreorder($one['preorder']['id'], $one['payment_id'])->assertOk();
        $this->verifyPreorder($one['preorder']['id'], $one['payment_id'])->assertStatus(409);
    }

    // =================================================================================
    // US2 — siapa dan kapan, serta jejak audit
    // =================================================================================

    public function test_verification_writes_exactly_one_audit_row_with_old_and_new_values(): void
    {
        $s = $this->sale();
        $verifier = $this->userWithRole('admin');

        $this->as($verifier)->verify($s['order']['id'], $s['payment_id'])->assertOk();

        $logs = ActivityLog::where('action', 'payment_verified')->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($verifier->id, (int) $log->user_id);
        $this->assertSame('Order', $log->entity_type);
        $this->assertSame($s['order']['id'], (int) $log->entity_id);
        $this->assertSame(['payment_id' => $s['payment_id'], 'verification' => 'pending'], $log->old_values);
        $this->assertSame($s['payment_id'], $log->new_values['payment_id']);
        $this->assertSame('verified', $log->new_values['verification']);
        $this->assertSame($verifier->id, $log->new_values['verified_by']);
        $this->assertNotEmpty($log->new_values['verified_at']);
    }

    public function test_the_preorder_audit_row_is_typed_preorder(): void
    {
        $p = $this->preorder();
        $this->as($this->userWithRole('owner'))->verifyPreorder($p['preorder']['id'], $p['payment_id'])->assertOk();

        $log = ActivityLog::where('action', 'payment_verified')->firstOrFail();
        $this->assertSame('Preorder', $log->entity_type);
        $this->assertSame($p['preorder']['id'], (int) $log->entity_id);
    }

    public function test_refused_attempts_leave_no_audit_row(): void
    {
        $s = $this->sale();
        $cash = $this->sale(payments: [['method' => 'cash', 'amount' => 50000]]);

        $this->as($this->recorder)->verify($s['order']['id'], $s['payment_id'])->assertForbidden();
        $owner = $this->userWithRole('owner');
        $this->as($owner)->verify($cash['order']['id'], $cash['payment_id'])->assertStatus(422);
        $this->verify($s['order']['id'], 999999)->assertNotFound();
        Order::whereKey($s['order']['id'])->update(['status' => 'voided']);
        $this->verify($s['order']['id'], $s['payment_id'])->assertStatus(409);

        $this->assertSame(0, ActivityLog::where('action', 'payment_verified')->count());
    }

    public function test_payloads_carry_the_verifier_name_and_time_once_verified_and_nothing_before(): void
    {
        $s = $this->sale();
        $verifier = $this->userWithRole('admin');
        $verifier->update(['name' => 'Pemeriksa Satu']);

        $pending = $this->as($this->userWithRole('owner'))->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $this->assertSame('pending', $pending['verification']);
        $this->assertNull($pending['verified_by_name']);
        $this->assertNull($pending['verified_at']);
        $this->assertTrue($pending['can_verify']);

        $after = $this->as($verifier)->verify($s['order']['id'], $s['payment_id'])->assertOk()->json('payments.0');
        $this->assertSame('verified', $after['verification']);
        $this->assertSame('Pemeriksa Satu', $after['verified_by_name']);
        $this->assertNotNull($after['verified_at']);
        $this->assertFalse($after['can_verify']);
    }

    public function test_the_preorder_payload_carries_the_same_fields(): void
    {
        $p = $this->preorder();
        $verifier = $this->userWithRole('admin');
        $verifier->update(['name' => 'Pemeriksa Dua']);

        $after = $this->as($verifier)->verifyPreorder($p['preorder']['id'], $p['payment_id'])->assertOk()->json('payments.0');
        $this->assertSame('verified', $after['verification']);
        $this->assertSame('Pemeriksa Dua', $after['verified_by_name']);
        $this->assertNotNull($after['verified_at']);

        $show = $this->as($this->recorder)->getJson("/api/v1/preorders/{$p['preorder']['id']}")->json('payments.0');
        $this->assertSame('Pemeriksa Dua', $show['verified_by_name']);
        $this->assertFalse($show['can_verify']);
    }

    public function test_can_verify_follows_who_is_asking_and_the_state_of_the_payment(): void
    {
        $s = $this->sale();
        $cash = $this->sale(payments: [['method' => 'cash', 'amount' => 50000]]);

        $asRecorder = $this->as($this->recorder)->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $asOther = $this->as($this->userWithRole('cashier'))->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $cashEntry = $this->as($this->userWithRole('owner'))->getJson("/api/v1/orders/{$cash['order']['id']}")->json('payments.0');

        $this->assertFalse($asRecorder['can_verify']);   // pencatat: tidak boleh memverifikasi miliknya
        $this->assertTrue($asOther['can_verify']);
        $this->assertFalse($cashEntry['can_verify']);    // tunai: sudah terverifikasi sejak dicatat

        Order::whereKey($s['order']['id'])->update(['status' => 'voided']);
        $voided = $this->as($this->userWithRole('owner'))->getJson("/api/v1/orders/{$s['order']['id']}")->json('payments.0');
        $this->assertFalse($voided['can_verify']);
    }

    public function test_cash_payments_keep_verified_without_inventing_a_verifier(): void
    {
        $cash = $this->sale(payments: [['method' => 'cash', 'amount' => 50000]]);

        $entry = $this->as($this->userWithRole('owner'))->getJson("/api/v1/orders/{$cash['order']['id']}")->json('payments.0');

        $this->assertSame('verified', $entry['verification']);
        $this->assertNull($entry['verified_by_name']);
        $this->assertNull($entry['verified_at']);
    }

    public function test_when_the_verifier_user_is_later_deleted_the_time_stays_and_the_name_is_null(): void
    {
        $s = $this->sale();
        $verifier = $this->userWithRole('admin');
        $this->as($verifier)->verify($s['order']['id'], $s['payment_id'])->assertOk();

        DB::table('personal_access_tokens')->where('tokenable_id', $verifier->id)->delete();
        DB::table('users')->where('id', $verifier->id)->delete();

        $entry = $this->as($this->userWithRole('owner'))->getJson("/api/v1/orders/{$s['order']['id']}")->assertOk()->json('payments.0');
        $this->assertSame('verified', $entry['verification']);
        $this->assertNull($entry['verified_by_name']);
        $this->assertNotNull($entry['verified_at']);
    }
}
