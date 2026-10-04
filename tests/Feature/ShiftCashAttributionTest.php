<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 028-partial-split-payment (US5, research Decision 7) — uang tunai dihitung di
 * shift TEMPAT IA DITERIMA (`payments.session_id`), bukan shift penjualan
 * aslinya: pembayaran susulan tidak boleh merusak rekonsiliasi shift yang
 * sudah ditutup (expected_cash tersimpan).
 */
class ShiftCashAttributionTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Event $event;

    private ProductVariant $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->event = Event::factory()->create(['status' => 'active']);

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'SHFT0001', 'sell_price' => 100000, 'cost_price' => 40000, 'current_stock' => 100,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function openShift(float $openingCash = 0): CashierSession
    {
        return CashierSession::factory()->create([
            'event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => $openingCash,
        ]);
    }

    private function sell(CashierSession $session, int $qty, float $cash, bool $withCustomer = true): array
    {
        return $this->postJson('/api/v1/orders', array_filter([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $withCustomer ? $this->customer->id : null,
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $cash]],
        ], fn ($v) => $v !== null))->assertCreated()->json();
    }

    private function close(CashierSession $session, float $closingCash)
    {
        return $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => $closingCash])->assertOk();
    }

    public function test_cash_received_later_counts_in_the_receiving_shift_not_the_sales_shift(): void
    {
        $shiftA = $this->openShift(50000);
        $order = $this->sell($shiftA, 5, 200000); // total 500.000, terima 200.000 di shift A
        $closedA = $this->close($shiftA, 250000)->json();
        $this->assertEquals(250000, $closedA['expected_cash']); // 50.000 + 200.000

        $shiftB = $this->openShift(10000);
        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['method' => 'cash', 'amount' => 300000])->assertCreated();
        $closedB = $this->close($shiftB, 310000)->json();

        $this->assertEquals(310000, $closedB['expected_cash']);  // 10.000 + 300.000 yang DITERIMA di B
        $this->assertEquals(0, $closedB['cash_difference']);
        // shift A yang sudah ditutup tidak berubah
        $this->assertEquals(250000, CashierSession::findOrFail($shiftA->id)->expected_cash);
    }

    public function test_change_given_at_checkout_is_still_deducted_from_the_shift_of_the_sale(): void
    {
        $shift = $this->openShift(0);
        $this->sell($shift, 1, 120000, withCustomer: false); // total 100.000, kembalian 20.000

        $closed = $this->close($shift, 100000)->json();

        $this->assertEquals(100000, $closed['expected_cash']);
        $this->assertEquals(0, $closed['cash_difference']);
    }

    public function test_the_session_summary_groups_methods_by_the_shift_that_received_the_payment(): void
    {
        $shiftA = $this->openShift();
        $order = $this->sell($shiftA, 5, 200000);
        $this->close($shiftA, 200000);
        $shiftB = $this->openShift();
        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['method' => 'cash', 'amount' => 300000])->assertCreated();

        $summaryA = $this->getJson("/api/v1/sessions/{$shiftA->id}/summary")->assertOk()->json('by_method');
        $summaryB = $this->getJson("/api/v1/sessions/{$shiftB->id}/summary")->assertOk()->json('by_method');

        $this->assertEquals(200000, collect($summaryA)->firstWhere('method', 'cash')['amount']);
        $this->assertEquals(300000, collect($summaryB)->firstWhere('method', 'cash')['amount']);
    }

    public function test_voided_orders_are_excluded_from_every_shift(): void
    {
        $shiftA = $this->openShift();
        $order = $this->sell($shiftA, 5, 200000);
        $this->close($shiftA, 0);
        $shiftB = $this->openShift(10000);
        $this->postJson("/api/v1/orders/{$order['id']}/payments", ['method' => 'cash', 'amount' => 300000])->assertCreated();
        Order::whereKey($order['id'])->update(['status' => 'voided']);

        $closedB = $this->close($shiftB, 10000)->json();

        $this->assertEquals(10000, $closedB['expected_cash']); // pembayaran order batal tidak dihitung
    }

    public function test_pre_order_payments_stay_outside_shift_cash(): void
    {
        $shift = $this->openShift(5000);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", ['method' => 'cash', 'amount' => 50000, 'purpose' => 'down_payment'])->assertCreated();

        $closed = $this->close($shift, 5000)->json();

        $this->assertEquals(5000, $closed['expected_cash']);
        $this->assertNull(\App\Models\Payment::where('preorder_id', $preorder['id'])->first()->session_id);
    }

    public function test_the_backfill_semantics_checkout_payments_belong_to_the_sale_shift(): void
    {
        $shift = $this->openShift();
        $order = $this->sell($shift, 1, 100000, withCustomer: false);

        $this->assertDatabaseHas('payments', ['order_id' => $order['id'], 'session_id' => $shift->id]);
    }
}
