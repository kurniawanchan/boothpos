<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 028-partial-split-payment (US5) — penjualan POS yang dibayar sebagian:
 * checkout parsial (wajib pelanggan), pembayaran susulan lewat
 * POST /orders/{id}/payments, hapus pembayaran, dan ringkasan di payload.
 */
class PosPartialPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private CashierSession $session;

    private ProductVariant $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'POSP0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 10,
        ]);
        $this->customer = Customer::factory()->create();
    }

    /** Total 100.000 (4 × 25.000). */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 4]],
            'payments' => [['method' => 'cash', 'amount' => 40000]],
        ], $overrides);
    }

    private function partialOrder(float $paid = 40000, array $overrides = []): array
    {
        return $this->postJson('/api/v1/orders', $this->payload(array_merge([
            'customer_id' => $this->customer->id, 'payments' => [['method' => 'cash', 'amount' => $paid]],
        ], $overrides)))->assertCreated()->json();
    }

    private function pay(int $orderId, array $overrides = [])
    {
        return $this->postJson("/api/v1/orders/{$orderId}/payments", array_merge([
            'method' => 'cash', 'amount' => 10000,
        ], $overrides));
    }

    private function nonCash(float $amount): array
    {
        $channel = PaymentChannel::create([
            'type' => 'qr_ewallet', 'provider' => 'GoPay', 'account_name' => 'T', 'account_number' => '1', 'is_active' => true,
        ]);
        $token = $this->postJson('/api/v1/payment-proofs', [
            'file' => UploadedFile::fake()->image('b.jpg'), 'captured_via' => 'upload',
        ])->assertCreated()->json('proof_token');

        return ['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => $amount, 'proof_token' => $token];
    }

    // --- checkout parsial -----------------------------------------------------------

    public function test_a_partial_checkout_without_a_customer_is_refused_and_changes_nothing(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->payload());

        $response->assertStatus(409)->assertJsonValidationErrors('payments');
        $this->assertSame(0, Order::count());
        $this->assertSame(10, $this->variant->fresh()->current_stock);
    }

    public function test_a_partial_checkout_with_a_customer_completes_the_sale(): void
    {
        $order = $this->partialOrder(40000, [
            'payments' => [['method' => 'cash', 'amount' => 40000, 'reference' => 'DP-1']],
        ]);

        $this->assertSame('completed', $order['status']);
        $this->assertSame('0.00', $order['change_amount']);
        $this->assertSame('40000.00', $order['paid_amount']);
        $this->assertSame(6, $this->variant->fresh()->current_stock);
        $this->assertSame([
            'grand_total' => '100000.00', 'total_paid' => '40000.00', 'remaining' => '60000.00',
            'status' => 'partially_paid', 'payment_count' => 1,
        ], $order['payment_summary']);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order['id'], 'reference' => 'DP-1',
            'session_id' => $this->session->id, 'recorded_by' => $this->cashier->id,
        ]);
    }

    public function test_split_payments_that_still_fall_short_need_a_customer_too(): void
    {
        $this->postJson('/api/v1/orders', $this->payload([
            'payments' => [['method' => 'cash', 'amount' => 30000], ['method' => 'cash', 'amount' => 30000]],
        ]))->assertStatus(409);

        $this->partialOrder(0, [
            'payments' => [['method' => 'cash', 'amount' => 30000], ['method' => 'cash', 'amount' => 30000]],
        ]);
    }

    public function test_a_fully_paid_checkout_with_change_is_unchanged(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->payload([
            'payments' => [['method' => 'cash', 'amount' => 120000]],
        ]))->assertCreated()->json();

        $this->assertSame('20000.00', $order['change_amount']);
        $this->assertSame('100000.00', $order['payment_summary']['total_paid']);
        $this->assertSame('fully_paid', $order['payment_summary']['status']);
        $this->assertDatabaseHas('payments', ['order_id' => $order['id'], 'session_id' => $this->session->id, 'recorded_by' => $this->cashier->id]);
    }

    public function test_the_order_detail_carries_the_summary_and_enriched_payments(): void
    {
        $order = $this->partialOrder(40000, ['payments' => [['method' => 'cash', 'amount' => 40000, 'reference' => 'R-9']]]);

        $detail = $this->getJson("/api/v1/orders/{$order['id']}")->assertOk()->json();

        $this->assertSame('partially_paid', $detail['payment_summary']['status']);
        $this->assertSame('R-9', $detail['payments'][0]['reference']);
        $this->assertSame($this->cashier->name, $detail['payments'][0]['recorded_by_name']);
        $this->assertSame('paid', $detail['payments'][0]['status']);
    }

    // --- pembayaran susulan -------------------------------------------------------------

    public function test_a_later_cash_payment_is_recorded_in_the_callers_open_shift(): void
    {
        $order = $this->partialOrder();

        $response = $this->pay($order['id'], ['amount' => 20000, 'reference' => 'L-1', 'client_ref' => (string) Str::uuid()])->assertCreated();

        $response->assertJsonPath('payment_summary.total_paid', '60000.00')
            ->assertJsonPath('payment_summary.remaining', '40000.00')
            ->assertJsonPath('paid_amount', '60000.00');
        $this->assertDatabaseHas('payments', ['order_id' => $order['id'], 'reference' => 'L-1', 'session_id' => $this->session->id, 'recorded_by' => $this->cashier->id]);
    }

    public function test_paying_the_remainder_makes_the_sale_fully_paid_and_it_stays_completed(): void
    {
        $order = $this->partialOrder();

        $this->pay($order['id'], ['amount' => 60000])->assertCreated()
            ->assertJsonPath('payment_summary.status', 'fully_paid')
            ->assertJsonPath('payment_summary.remaining', '0.00')
            ->assertJsonPath('status', 'completed');
    }

    public function test_cash_without_an_open_shift_is_refused(): void
    {
        $order = $this->partialOrder();
        $this->session->update(['status' => 'closed']);

        $this->pay($order['id'])->assertStatus(409);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_non_cash_payment_does_not_need_an_open_shift_and_has_no_session(): void
    {
        $order = $this->partialOrder();
        $this->session->update(['status' => 'closed']);

        $this->postJson("/api/v1/orders/{$order['id']}/payments", $this->nonCash(10000))->assertCreated();

        $this->assertNull(Payment::where('order_id', $order['id'])->latest('id')->first()->session_id);
    }

    public function test_amount_above_the_remaining_balance_fully_paid_and_voided_are_refused(): void
    {
        $order = $this->partialOrder(); // sisa 60.000

        $this->pay($order['id'], ['amount' => 60001])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->pay($order['id'], ['amount' => 60000])->assertCreated();
        $this->pay($order['id'], ['amount' => 1])->assertStatus(409); // lunas

        $voided = $this->partialOrder();
        Order::whereKey($voided['id'])->update(['status' => 'voided']);
        $this->pay($voided['id'])->assertStatus(409);
    }

    public function test_a_replayed_client_ref_records_one_payment(): void
    {
        $order = $this->partialOrder();
        $ref = (string) Str::uuid();

        $this->pay($order['id'], ['client_ref' => $ref])->assertCreated();
        $this->pay($order['id'], ['client_ref' => $ref])->assertOk();

        $this->assertDatabaseCount('payments', 2); // 1 saat checkout + 1 susulan
    }

    public function test_a_later_payment_writes_an_activity_log_row(): void
    {
        $order = $this->partialOrder();

        $this->pay($order['id'], ['amount' => 10000, 'reference' => 'AUD'])->assertCreated();

        $log = \App\Models\ActivityLog::where('action', 'payment_recorded')->firstOrFail();
        $this->assertSame('Order', $log->entity_type);
        $this->assertSame($order['id'], $log->entity_id);
        $this->assertSame('AUD', $log->new_values['reference']);
        $this->assertSame('50000.00', $log->new_values['total_paid']);
    }

    // --- hapus pembayaran -----------------------------------------------------------------

    public function test_only_owner_or_admin_can_delete_an_order_payment_and_it_recalculates(): void
    {
        $order = $this->partialOrder();
        $late = $this->pay($order['id'], ['amount' => 20000])->assertCreated()->json('payments.1');

        $this->deleteJson("/api/v1/orders/{$order['id']}/payments/{$late['id']}")->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->deleteJson("/api/v1/orders/{$order['id']}/payments/{$late['id']}")->assertOk()
            ->assertJsonPath('payment_summary.total_paid', '40000.00')
            ->assertJsonPath('payment_summary.remaining', '60000.00');
        $this->assertDatabaseMissing('payments', ['id' => $late['id']]);
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'payment_deleted')->count());
    }

    public function test_deleting_a_payment_of_a_voided_order_is_refused(): void
    {
        $order = $this->partialOrder();
        $payment = Payment::where('order_id', $order['id'])->firstOrFail();
        Order::whereKey($order['id'])->update(['status' => 'voided']);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->deleteJson("/api/v1/orders/{$order['id']}/payments/{$payment->id}")->assertStatus(409);
    }

    public function test_a_cash_payment_of_a_closed_shift_cannot_be_deleted_but_non_cash_can(): void
    {
        $order = $this->partialOrder(40000);
        $nonCashPayment = $this->postJson("/api/v1/orders/{$order['id']}/payments", $this->nonCash(10000))->assertCreated()->json('payments.1');
        $cashPayment = Payment::where('order_id', $order['id'])->where('method', 'cash')->firstOrFail();
        $this->postJson("/api/v1/sessions/{$this->session->id}/close", ['closing_cash' => 40000])->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->deleteJson("/api/v1/orders/{$order['id']}/payments/{$cashPayment->id}")->assertStatus(409);
        $this->assertDatabaseHas('payments', ['id' => $cashPayment->id]);

        $this->deleteJson("/api/v1/orders/{$order['id']}/payments/{$nonCashPayment['id']}")->assertOk();
    }

    public function test_a_payment_of_another_order_is_not_found(): void
    {
        $a = $this->partialOrder();
        $b = $this->partialOrder();
        $payment = Payment::where('order_id', $b['id'])->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->deleteJson("/api/v1/orders/{$a['id']}/payments/{$payment->id}")->assertNotFound();
    }

    // --- struk ---------------------------------------------------------------------------------

    public function test_the_receipt_shows_paid_balance_status_and_payment_references(): void
    {
        $order = $this->partialOrder(40000, ['payments' => [['method' => 'cash', 'amount' => 40000, 'reference' => 'DP-1']]]);
        $this->pay($order['id'], ['amount' => 10000])->assertCreated();

        $receipt = $this->getJson("/api/v1/orders/{$order['id']}/receipt")->assertOk()->json();

        $this->assertSame('50000.00', $receipt['paid_amount']);
        $this->assertSame('50000.00', $receipt['balance_amount']);
        $this->assertSame('partially_paid', $receipt['payment_status']);
        $this->assertSame('DP-1', $receipt['payment_summary'][0]['reference']);
        $this->assertCount(2, $receipt['payment_summary']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $order = $this->partialOrder();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['method' => 'cash', 'amount' => 1])->assertStatus(401);
    }
}
