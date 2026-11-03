<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 054 (US11) — GET /reports/sales-by-payment-method: jumlah dan jumlah transaksi per metode/kanal, tunai
 * bersih dari kembalian, split payment dihitung per porsi, verifikasi terpisah, dan daftar transaksi per
 * metode. Total HARUS cocok dengan halaman Sales untuk cakupan yang sama.
 */
class SalesByPaymentMethodReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Event $event;

    private CashierSession $session;

    private ProductVariant $variant;

    private PaymentChannel $qris;

    private PaymentChannel $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'PMR0001', 'sell_price' => 25000, 'cost_price' => 1, 'current_stock' => 1000]);
        $this->qris = PaymentChannel::factory()->create(['type' => 'qr_ewallet', 'provider' => 'QRIS BCA']);
        $this->bank = PaymentChannel::factory()->create(['type' => 'bank_transfer', 'provider' => 'Mandiri']);
        $this->actingAs($this->owner, 'sanctum');
    }

    /** Penjualan 50.000 (2 × 25.000) dengan pembayaran $payments. */
    private function sale(array $payments): Order
    {
        return app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'payments' => $payments,
        ], $this->cashier);
    }

    private function qris(float $amount): array
    {
        return ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id, 'amount' => $amount];
    }

    private function report(array $q = []): array
    {
        return $this->getJson('/api/v1/reports/sales-by-payment-method?'.http_build_query($q + ['event_id' => $this->event->id]))->assertOk()->json();
    }

    private function row(array $report, string $method, ?int $channel = null): ?array
    {
        return collect($report['data'])->first(fn ($r) => $r['method'] === $method && $r['channel_id'] === $channel);
    }

    private function seedSales(): void
    {
        $this->sale([['method' => 'cash', 'amount' => 60000]]);                          // kembalian 10.000 → tunai bersih 50.000
        $this->sale([['method' => 'cash', 'amount' => 20000], $this->qris(30000)]);        // split
        $this->sale([['method' => 'bank_transfer', 'channel_id' => $this->bank->id, 'amount' => 50000]]);
    }

    public function test_amounts_and_counts_per_method_with_cash_net_of_change_and_split_by_portion(): void
    {
        $this->seedSales();

        $report = $this->report();

        $cash = $this->row($report, 'cash');
        $this->assertSame('70000.00', $cash['amount']);          // 50.000 (bersih) + 20.000
        $this->assertSame(2, $cash['transaction_count']);
        $qris = $this->row($report, 'qr_ewallet', $this->qris->id);
        $this->assertSame('30000.00', $qris['amount']);
        $this->assertSame(1, $qris['transaction_count']);
        $this->assertSame('QRIS BCA', $qris['label']);
        $bank = $this->row($report, 'bank_transfer', $this->bank->id);
        $this->assertSame('50000.00', $bank['amount']);

        $this->assertSame('150000.00', $report['totals']['amount']);
        $this->assertSame(3, $report['totals']['transaction_count'], 'the split sale counts once in the total');
    }

    public function test_verified_and_unverified_amounts_are_separated_for_non_cash(): void
    {
        $this->seedSales();
        Payment::where('method', 'bank_transfer')->update(['verification' => 'verified']);

        $report = $this->report();

        $this->assertSame('50000.00', $this->row($report, 'bank_transfer', $this->bank->id)['verified_amount']);
        $this->assertSame('0.00', $this->row($report, 'bank_transfer', $this->bank->id)['unverified_amount']);
        $this->assertSame('30000.00', $this->row($report, 'qr_ewallet', $this->qris->id)['unverified_amount']);
        $this->assertSame('70000.00', $this->row($report, 'cash')['verified_amount']);
        $this->assertSame('30000.00', $report['totals']['unverified_amount']);
    }

    public function test_the_grand_total_equals_the_sales_page_cash_plus_non_cash(): void
    {
        $this->seedSales();

        $page = collect($this->getJson('/api/v1/reports/sales?event_id='.$this->event->id)->json('transactions'));
        $salesTotal = $page->sum(fn ($r) => (float) $r['cash_amount'] + (float) $r['noncash_amount']);

        $this->assertEqualsWithDelta($salesTotal, (float) $this->report()['totals']['amount'], 0.001);
        $this->assertEqualsWithDelta($page->sum(fn ($r) => (float) $r['cash_amount']), (float) $this->row($this->report(), 'cash')['amount'], 0.001);
    }

    public function test_voided_sales_and_rejected_payments_are_excluded(): void
    {
        $this->seedSales();
        $voided = $this->sale([$this->qris(50000)]);
        app(OrderService::class)->void($voided, 'salah', $this->owner);
        $rejected = $this->sale([$this->qris(50000)]);
        $rejected->payments()->update(['verification' => 'rejected']);

        $report = $this->report();

        $this->assertSame('30000.00', $this->row($report, 'qr_ewallet', $this->qris->id)['amount']);
        $this->assertSame(3, $report['totals']['transaction_count']);
    }

    public function test_a_corrected_payment_appears_only_under_its_new_method(): void
    {
        $order = $this->sale([['method' => 'cash', 'amount' => 50000]]);
        $this->actingAs($this->cashier, 'sanctum')
            ->patchJson("/api/v1/orders/{$order->id}/payments/{$order->payments->first()->id}/method", ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertOk();
        $this->actingAs($this->owner, 'sanctum');

        $report = $this->report();

        $this->assertNull($this->row($report, 'cash'));
        $this->assertSame('50000.00', $this->row($report, 'qr_ewallet', $this->qris->id)['amount']);
    }

    public function test_other_data_mode_event_and_dates_are_respected(): void
    {
        $this->seedSales();
        $demo = $this->sale([['method' => 'cash', 'amount' => 50000]]);
        DB::table('orders')->where('id', $demo->id)->update(['data_mode' => 'demo']);
        DB::table('payments')->where('order_id', $demo->id)->update(['data_mode' => 'demo']);

        $this->assertSame('70000.00', $this->row($this->report(), 'cash')['amount']);
        $this->assertSame([], $this->report(['date_from' => '2999-01-01'])['data']);
        $this->assertSame([], $this->report(['event_id' => Event::factory()->create()->id])['data']);
    }

    public function test_the_drill_down_lists_the_sales_paid_by_a_method_with_that_methods_portion(): void
    {
        $this->seedSales();

        $cash = $this->getJson('/api/v1/reports/sales-by-payment-method/transactions?'.http_build_query(['method' => 'cash', 'event_id' => $this->event->id]))->assertOk()->json();
        $this->assertSame(2, $cash['meta']['total']);
        $this->assertEqualsCanonicalizing(['50000.00', '20000.00'], collect($cash['data'])->pluck('amount')->all());

        $qris = $this->getJson('/api/v1/reports/sales-by-payment-method/transactions?'.http_build_query(['method' => 'qr_ewallet', 'channel_id' => $this->qris->id, 'event_id' => $this->event->id]))->json();
        $this->assertSame(1, $qris['meta']['total']);
        $this->assertSame('30000.00', $qris['data'][0]['amount']);
        $this->assertSame('pending', $qris['data'][0]['verification']);
        $this->assertSame($this->cashier->name, $qris['data'][0]['cashier_name']);
    }

    public function test_the_drill_down_count_equals_the_row_count_and_paginates(): void
    {
        $this->seedSales();
        $row = $this->row($this->report(), 'cash');

        $page1 = $this->getJson('/api/v1/reports/sales-by-payment-method/transactions?'.http_build_query(['method' => 'cash', 'event_id' => $this->event->id, 'per_page' => 1]))->json();

        $this->assertSame($row['transaction_count'], $page1['meta']['total']);
        $this->assertCount(1, $page1['data']);
        $this->assertSame(2, $page1['meta']['last_page']);
    }

    public function test_a_user_without_the_reports_menu_is_refused_and_a_bad_method_is_rejected(): void
    {
        $this->seedSales();

        $this->getJson('/api/v1/reports/sales-by-payment-method/transactions?method=bitcoin')->assertStatus(422);

        $this->actingAs($this->cashier, 'sanctum');
        $this->getJson('/api/v1/reports/sales-by-payment-method')->assertStatus(403);
        $this->getJson('/api/v1/reports/sales-by-payment-method/transactions?method=cash')->assertStatus(403);
    }

    public function test_an_empty_scope_returns_an_empty_list(): void
    {
        $report = $this->report();

        $this->assertSame([], $report['data']);
        $this->assertSame('0.00', $report['totals']['amount']);
        $this->assertSame(0, $report['totals']['transaction_count']);
    }
}
