<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 028-partial-split-payment (US5) — halaman Sales: status pembayaran per baris,
 * sisa tagihan, dan kas shift yang dihitung dari tempat uang diterima.
 * Laporan agregat TIDAK berubah (penjualan tetap dihitung saat selesai).
 */
class SalesPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Event $event;

    private CashierSession $session;

    private $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create(['sku' => 'SPST0001', 'sell_price' => 100000, 'cost_price' => 40000, 'current_stock' => 100]);
        $this->customer = Customer::factory()->create();
    }

    private function sell(float $cash, int $qty = 5, ?CashierSession $session = null): Order
    {
        return app(OrderService::class)->create([
            'session_id' => ($session ?? $this->session)->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $cash]],
        ], $this->cashier);
    }

    private function report(): array
    {
        return $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk()->json();
    }

    private function row(array $report, Order $order): array
    {
        return collect($report['transactions'])->firstWhere('id', $order->id);
    }

    public function test_rows_carry_paid_balance_and_payment_status(): void
    {
        $partial = $this->sell(200000);          // total 500.000
        $full = $this->sell(100000, qty: 1);     // lunas

        $report = $this->report();

        $this->assertSame('partially_paid', $this->row($report, $partial)['payment_status']);
        $this->assertSame('200000.00', $this->row($report, $partial)['paid_amount']);
        $this->assertSame('300000.00', $this->row($report, $partial)['balance_amount']);
        $this->assertSame('fully_paid', $this->row($report, $full)['payment_status']);
        $this->assertSame('0.00', $this->row($report, $full)['balance_amount']);
    }

    public function test_a_row_with_cash_change_is_fully_paid_with_the_sale_total_as_paid(): void
    {
        $order = $this->sell(120000, qty: 1); // kembalian 20.000

        $row = $this->row($this->report(), $order);

        $this->assertSame('100000.00', $row['paid_amount']);
        $this->assertSame('fully_paid', $row['payment_status']);
    }

    public function test_sales_totals_do_not_depend_on_the_payment_status(): void
    {
        $partial = $this->sell(200000);
        $partialTotals = $this->report()['totals'];
        $this->postJson("/api/v1/orders/{$partial->id}/payments", ['method' => 'cash', 'amount' => 300000])->assertCreated();

        $this->assertEquals($partialTotals, $this->report()['totals']);
    }

    public function test_sessions_carry_cash_received_and_include_a_shift_that_only_got_late_payments(): void
    {
        $order = $this->sell(200000);
        $this->postJson("/api/v1/sessions/{$this->session->id}/close", ['closing_cash' => 200000])->assertOk();
        $shiftB = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $this->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 300000])->assertCreated();

        $sessions = collect($this->report()['sessions'])->keyBy('id');

        $this->assertEquals(200000, $sessions[$this->session->id]['cash_received']);
        $this->assertTrue($sessions->has($shiftB->id));
        $this->assertEquals(300000, $sessions[$shiftB->id]['cash_received']);
    }

    public function test_cash_received_deducts_the_change_given_in_that_shift(): void
    {
        $this->sell(120000, qty: 1); // 100.000 total, kembalian 20.000

        $session = collect($this->report()['sessions'])->firstWhere('id', $this->session->id);

        $this->assertEquals(100000, $session['cash_received']);
    }
}
