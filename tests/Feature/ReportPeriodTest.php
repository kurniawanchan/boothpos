<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BusinessDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 046-hide-events-preorders (US2) — rekap outlet, laba, dan modal outlet berskala PERIODE tanggal bisnis
 * (bawaan hari bisnis berjalan), dihitung langsung dari penjualan selesai: tanpa event, tanpa pre-order,
 * tanpa snapshot yang ditulis. Menggantikan SellerRecapPosOnlyTest dan ReportCostPosPreorderSplitTest.
 */
class ReportPeriodTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\TreatsOrdersAsLegacy;

    private User $owner;

    private User $cashier;

    private CashierSession $session;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->session = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open']);
        $this->category = Category::factory()->create();

        $this->actingAs($this->owner, 'sanctum');
    }

    /** @return array{0: Outlet, 1: ProductVariant} */
    private function seller(string $code, float $price = 10000, float $cost = 4000): array
    {
        $outlet = Outlet::factory()->create(['code' => $code, 'name' => "Outlet {$code}"]);
        $product = Product::factory()->create(['home_outlet_id' => $outlet->id, 'category_id' => $this->category->id]);
        $variant = $product->variants()->create([
            'sku' => $code.'KYAAA0001', 'sell_price' => $price, 'cost_price' => $cost, 'current_stock' => 1000,
        ]);

        return [$outlet, $variant];
    }

    private function sale(ProductVariant $variant, int $qty = 1)
    {
        return app(OrderService::class)->create([
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $variant->sell_price * $qty]],
        ], $this->cashier);
    }

    private function recapRows(string $query = ''): \Illuminate\Support\Collection
    {
        return collect($this->getJson("/api/v1/reports/outlet-settlements{$query}")->assertOk()->json('data'))->keyBy('outlet_id');
    }

    public function test_the_recap_defaults_to_the_current_business_day(): void
    {
        [$a, $va] = $this->seller('AAA');
        $old = $this->sale($va, 5);
        DB::table('orders')->where('id', $old->id)->update(['business_date' => '2020-01-01']);
        $this->sale($va, 2);

        $row = $this->recapRows()[$a->id];

        $this->assertSame(['20000.00', 2], [$row['total_sales'], $row['total_units']]);
    }

    public function test_a_date_range_selects_the_sales_inside_it(): void
    {
        [$a, $va] = $this->seller('AAA');
        $old = $this->sale($va, 5);
        DB::table('orders')->where('id', $old->id)->update(['business_date' => '2020-01-01']);
        $this->sale($va, 2);

        $past = $this->recapRows('?date_from=2020-01-01&date_to=2020-01-31')[$a->id];
        $all = $this->recapRows('?date_from=2020-01-01&date_to='.BusinessDay::today())[$a->id];

        $this->assertSame('50000.00', $past['total_sales']);
        $this->assertSame('70000.00', $all['total_sales']);
    }

    public function test_voided_orders_count_nowhere(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->sale($va, 1);
        $voided = $this->sale($va, 4);
        app(OrderService::class)->void($voided, 'salah input', $this->owner);

        $this->assertSame('10000.00', $this->recapRows()[$a->id]['total_sales']);
    }

    public function test_the_recap_writes_no_settlement_snapshot_and_has_only_the_screen_fields(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->sale($va, 1);

        $row = $this->recapRows()[$a->id];

        $this->assertEqualsCanonicalizing(['outlet_id', 'outlet_name', 'historical', 'total_sales', 'total_units'], array_keys($row));
        $this->assertSame(0, DB::table('outlet_settlements')->count());
    }

    public function test_an_inverted_range_is_rejected(): void
    {
        $this->getJson('/api/v1/reports/outlet-settlements?date_from=2026-02-02&date_to=2026-01-01')->assertStatus(422);
        $this->getJson('/api/v1/reports/profit?date_from=nonsense')->assertStatus(422);
    }

    public function test_profit_is_revenue_cost_and_gross_for_the_period_only(): void
    {
        [, $va] = $this->seller('AAA', 10000, 4000);
        $this->sale($va, 3);
        $old = $this->sale($va, 10);
        DB::table('orders')->where('id', $old->id)->update(['business_date' => '2020-01-01']);

        $today = $this->getJson('/api/v1/reports/profit')->assertOk()->json();

        $this->assertSame(['30000.00', '12000.00', '18000.00'], [$today['revenue'], $today['cost_of_goods'], $today['gross_profit']]);
        foreach (['event', 'event_cost', 'net_profit', 'revenue_pos', 'revenue_preorder'] as $gone) {
            $this->assertArrayNotHasKey($gone, $today);
        }

        $past = $this->getJson('/api/v1/reports/profit?date_from=2020-01-01&date_to=2020-01-01')->assertOk()->json();
        $this->assertSame('100000.00', $past['revenue']);
    }

    public function test_outlet_profit_follows_the_period(): void
    {
        [$a, $va] = $this->seller('AAA', 10000, 4000);
        $old = $this->sale($va, 2);
        DB::table('orders')->where('id', $old->id)->update(['business_date' => '2020-01-01']);
        $this->sale($va, 1);

        $rows = collect($this->getJson('/api/v1/reports/outlet-profit')->assertOk()->json('data'))->keyBy('outlet_id');

        $this->assertSame(['10000.00', '4000.00', '6000.00'], [$rows[$a->id]['total_sales'], $rows[$a->id]['modal'], $rows[$a->id]['gross_profit']]);
    }

    public function test_cost_reports_are_forbidden_for_cashier_and_inventory(): void
    {
        foreach (['cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');

            foreach (['profit', 'outlet-settlements', 'outlet-profit'] as $report) {
                $this->getJson("/api/v1/reports/{$report}")->assertStatus(403);
            }
        }
    }

    public function test_recap_export_is_a_two_sheet_workbook_that_matches_the_screen(): void
    {
        [$a, $va] = $this->seller('AAA');
        $this->sale($va, 2);

        $response = $this->get('/api/v1/reports/outlet-settlements/export');
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'recap').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        unlink($tmp);

        $summary = $book->getSheet(0)->toArray(null, true, true, false);
        $detail = $book->getSheet(1)->toArray(null, true, true, false);

        $this->assertSame(['outlet_id', 'outlet_name', 'historical', 'total_sales', 'total_units'], $summary[0]);
        $this->assertSame('Rekap', $book->getSheet(0)->getTitle());
        $this->assertSame('Detail Transaksi', $book->getSheet(1)->getTitle());
        $this->assertCount(2, $detail); // header + satu baris item
        $this->assertEquals(20000, $detail[1][5]);
    }

    public function test_the_removed_preorder_report_is_gone(): void
    {
        $this->getJson('/api/v1/reports/preorders')->assertNotFound();
        $this->get('/api/v1/reports/preorder/export')->assertNotFound();
    }
}
