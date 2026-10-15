<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 028-partial-split-payment (US1–US3) — pembayaran pre-order sebagai buku besar:
 * tiap pembayaran tersimpan sendiri-sendiri dan langsung, dengan ringkasan
 * (grand total / total terbayar / sisa / status) dan riwayat yang bisa diaudit.
 */
class PreorderPaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductVariant $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->actingAs($this->user, 'sanctum');

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'LEDG0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    /** Total 200.000. */
    private function order(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json();
    }

    private function pay(int $id, array $overrides = [])
    {
        return $this->postJson("/api/v1/preorders/{$id}/payments", array_merge([
            'method' => 'cash', 'amount' => 50000, 'purpose' => 'down_payment',
        ], $overrides));
    }

    private function nonCash(float $amount, string $method = 'bank_transfer'): array
    {
        $channel = PaymentChannel::create([
            'type' => $method, 'provider' => 'BCA', 'account_name' => 'Test', 'account_number' => '123', 'is_active' => true,
        ]);
        $token = $this->postJson('/api/v1/payment-proofs', [
            'file' => UploadedFile::fake()->image('bukti.jpg'), 'captured_via' => 'upload',
        ])->assertCreated()->json('proof_token');

        return ['method' => $method, 'channel_id' => $channel->id, 'amount' => $amount, 'proof_token' => $token, 'purpose' => 'settlement'];
    }

    // --- US1: pembayaran sebagian langsung tersimpan --------------------------

    public function test_a_partial_payment_is_saved_immediately_with_a_summary(): void
    {
        $order = $this->order();

        $response = $this->pay($order['id'], ['amount' => 50000])->assertCreated();

        $response->assertJsonPath('payment_summary.grand_total', '200000.00')
            ->assertJsonPath('payment_summary.total_paid', '50000.00')
            ->assertJsonPath('payment_summary.remaining', '150000.00')
            ->assertJsonPath('payment_summary.status', 'partially_paid')
            ->assertJsonPath('payment_summary.payment_count', 1)
            ->assertJsonPath('paid_amount', '50000.00')
            ->assertJsonPath('outstanding', '150000.00');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_the_summary_is_unpaid_before_any_payment(): void
    {
        $order = $this->order();

        $this->getJson("/api/v1/preorders/{$order['id']}")->assertOk()
            ->assertJsonPath('payment_summary.status', 'unpaid')
            ->assertJsonPath('payment_summary.total_paid', '0.00')
            ->assertJsonPath('payment_summary.remaining', '200000.00')
            ->assertJsonPath('payment_summary.payment_count', 0);
    }

    public function test_each_entry_carries_reference_recorder_time_and_status(): void
    {
        $order = $this->order();

        $entry = $this->pay($order['id'], ['reference' => 'TRX-8841'])->assertCreated()->json('payments.0');

        $this->assertSame('TRX-8841', $entry['reference']);
        $this->assertSame('Kasir Satu', $entry['recorded_by_name']);
        $this->assertSame('paid', $entry['status']);
        $this->assertSame('cash', $entry['method']);
        $this->assertSame('50000.00', $entry['amount']);
        $this->assertNotEmpty($entry['paid_at']);
        $this->assertDatabaseHas('payments', [
            'preorder_id' => $order['id'], 'reference' => 'TRX-8841', 'recorded_by' => $this->user->id,
        ]);
    }

    public function test_the_entry_without_a_reference_has_null_reference(): void
    {
        $order = $this->order();

        $this->pay($order['id'])->assertCreated()->assertJsonPath('payments.0.reference', null);
    }

    public function test_entries_by_different_methods_are_stored_independently(): void
    {
        $order = $this->order();
        $this->pay($order['id'], ['amount' => 50000])->assertCreated();

        $response = $this->postJson("/api/v1/preorders/{$order['id']}/payments", $this->nonCash(30000))->assertCreated();

        $this->assertSame(['cash', 'bank_transfer'], array_column($response->json('payments'), 'method'));
        $response->assertJsonPath('payment_summary.total_paid', '80000.00')->assertJsonPath('payment_summary.payment_count', 2);
    }

    public function test_the_first_payment_moves_ordered_to_deposit_paid_as_before(): void
    {
        $order = $this->order();

        $this->pay($order['id'])->assertCreated()->assertJsonPath('status', 'dp_paid');
    }

    public function test_amounts_that_are_not_positive_numbers_are_refused(): void
    {
        $order = $this->order();

        foreach ([0, -5, 'abc', ''] as $bad) {
            $this->pay($order['id'], ['amount' => $bad])->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->assertDatabaseCount('payments', 0);
    }

    // 031-optional-payment-proof — dulu "non-tunai wajib punya bukti"; sekarang bukti
    // opsional, tapi kanal tetap wajib (DB chk_payments_channel) dan kini ditolak
    // bersih (422), bukan 500 dari constraint.
    public function test_a_non_cash_payment_without_a_channel_is_refused_cleanly(): void
    {
        $order = $this->order();

        $this->pay($order['id'], ['method' => 'bank_transfer', 'amount' => 50000])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_non_cash_payment_with_a_channel_but_no_proof_is_accepted(): void
    {
        $order = $this->order();
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);

        $response = $this->pay($order['id'], ['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 50000])->assertCreated();

        $this->assertDatabaseCount('payment_proofs', 0);
        $this->assertSame('50000.00', $response->json('payments.0.amount'));
        $this->assertSame('dp_paid', $response->json('status'));
    }

    public function test_a_non_cash_payment_with_an_unknown_proof_token_is_still_refused(): void
    {
        $order = $this->order();
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);

        $this->pay($order['id'], [
            'method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 50000,
            'proof_token' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_too_long_reference_is_refused(): void
    {
        $order = $this->order();

        $this->pay($order['id'], ['reference' => str_repeat('x', 101)])->assertStatus(422)->assertJsonValidationErrors('reference');
    }

    // --- US2: sampai lunas -----------------------------------------------------

    public function test_several_payments_reach_fully_paid_with_zero_remaining(): void
    {
        $order = $this->order();
        $this->pay($order['id'], ['amount' => 50000])->assertCreated();
        $this->pay($order['id'], ['amount' => 50000])->assertCreated();

        $response = $this->pay($order['id'], ['amount' => 100000, 'purpose' => 'settlement'])->assertCreated();

        $response->assertJsonPath('payment_summary.remaining', '0.00')
            ->assertJsonPath('payment_summary.status', 'fully_paid')
            ->assertJsonPath('payment_summary.payment_count', 3)
            ->assertJsonPath('paid_amount', '200000.00');
    }

    public function test_the_last_payment_settles_an_arrived_pre_order(): void
    {
        $order = $this->order();
        $this->pay($order['id'], ['amount' => 50000])->assertCreated();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'arrived'])->assertOk();
        $this->pay($order['id'], ['amount' => 100000, 'purpose' => 'settlement'])->assertCreated()
            ->assertJsonPath('status', 'arrived')->assertJsonPath('payment_summary.status', 'partially_paid');

        $this->pay($order['id'], ['amount' => 50000, 'purpose' => 'settlement'])->assertCreated()
            ->assertJsonPath('status', 'settled')->assertJsonPath('payment_summary.status', 'fully_paid');
    }

    // --- US3: riwayat dan audit ---------------------------------------------------

    public function test_the_history_lists_payments_in_the_order_they_were_recorded(): void
    {
        $order = $this->order();
        $this->pay($order['id'], ['amount' => 10000, 'reference' => 'A'])->assertCreated();
        $this->pay($order['id'], ['amount' => 20000, 'reference' => 'B'])->assertCreated();
        $this->pay($order['id'], ['amount' => 30000, 'reference' => 'C'])->assertCreated();

        $entries = $this->getJson("/api/v1/preorders/{$order['id']}")->assertOk()->json('payments');

        $this->assertSame(['A', 'B', 'C'], array_column($entries, 'reference'));
        $this->assertSame(['10000.00', '20000.00', '30000.00'], array_column($entries, 'amount'));
    }

    public function test_recording_a_payment_writes_one_activity_log_row(): void
    {
        $order = $this->order();

        $this->pay($order['id'], ['amount' => 50000, 'reference' => 'REF-1'])->assertCreated();

        $log = \App\Models\ActivityLog::where('action', 'payment_recorded')->firstOrFail();
        $this->assertSame('Preorder', $log->entity_type);
        $this->assertSame($order['id'], $log->entity_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertEquals(50000, $log->new_values['amount']);
        $this->assertSame('cash', $log->new_values['method']);
        $this->assertSame('REF-1', $log->new_values['reference']);
        $this->assertSame('150000.00', $log->new_values['remaining']);
        $this->assertSame('partially_paid', $log->new_values['status']);
    }

    public function test_a_payment_has_no_update_route(): void
    {
        $order = $this->order();
        $entry = $this->pay($order['id'])->assertCreated()->json('payments.0');

        $this->patchJson("/api/v1/preorders/{$order['id']}/payments/{$entry['id']}", ['amount' => 1])->assertStatus(405);
        $this->putJson("/api/v1/preorders/{$order['id']}/payments/{$entry['id']}", ['amount' => 1])->assertStatus(405);
        $this->assertEquals(50000, Payment::findOrFail($entry['id'])->amount);
    }

    public function test_deleting_a_payment_returns_the_recalculated_summary_and_logs_old_and_new(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $order = $this->order();
        $first = $this->pay($order['id'], ['amount' => 50000])->assertCreated()->json('payments.0');
        $this->pay($order['id'], ['amount' => 30000, 'purpose' => 'settlement'])->assertCreated();
        $this->actingAs($owner, 'sanctum');

        $response = $this->deleteJson("/api/v1/preorders/{$order['id']}/payments/{$first['id']}")->assertOk();

        $response->assertJsonPath('payment_summary.total_paid', '30000.00')
            ->assertJsonPath('payment_summary.remaining', '170000.00')
            ->assertJsonPath('payment_summary.payment_count', 1);
        $log = \App\Models\ActivityLog::where('action', 'payment_deleted')->firstOrFail();
        $this->assertEquals(50000, $log->old_values['amount']);
        $this->assertSame('30000.00', $log->new_values['total_paid']);
    }

    public function test_a_cashier_cannot_delete_a_payment(): void
    {
        $order = $this->order();
        $entry = $this->pay($order['id'])->assertCreated()->json('payments.0');

        $this->deleteJson("/api/v1/preorders/{$order['id']}/payments/{$entry['id']}")->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $entry['id']]);
    }
}
