<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 054 (US10) — GET /reports/sales-by-product: unit & nilai per produk, penanda Terlaris (3 teratas menurut
 * unit, seri ikut), pencarian nama, dan daftar transaksi per produk. Hanya penjualan POS selesai; tanpa
 * modal/margin; terisolasi DEMO/LIVE.
 */
class SalesByProductReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Event $event;

    private CashierSession $session;

    private Category $category;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $this->category = Category::factory()->create();
        $this->artist = Artist::factory()->create(['name' => 'Nekoyama']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function variant(string $productName, string $variantName = 'Std', ?Artist $artist = null, int $price = 10000): ProductVariant
    {
        $product = Product::factory()->create(['artist_id' => ($artist ?? $this->artist)->id, 'category_id' => $this->category->id, 'name' => $productName]);

        return $product->variants()->create(['sku' => 'SBP'.Str::upper(Str::random(8)), 'variant_name' => $variantName, 'sell_price' => $price, 'cost_price' => 4000, 'current_stock' => 10000]);
    }

    private function sell(ProductVariant $variant, int $qty, array $extra = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => $qty * (float) $variant->sell_price]],
        ], $extra), $this->cashier);
    }

    private function report(array $q = []): array
    {
        return $this->getJson('/api/v1/reports/sales-by-product?'.http_build_query($q + ['event_id' => $this->event->id]))->assertOk()->json();
    }

    private function row(array $report, string $name): array
    {
        return collect($report['data'])->firstWhere('product_name', $name);
    }

    // --- angka ---------------------------------------------------------------------

    public function test_units_sales_and_transaction_count_match_the_orders(): void
    {
        $pins = $this->variant('Pins', 'Orange', price: 15000);
        $this->sell($pins, 3);
        $this->sell($pins, 2);

        $row = $this->row($this->report(), 'Pins');

        $this->assertSame(5, $row['units']);
        $this->assertSame('75000.00', $row['sales']);
        $this->assertSame(2, $row['transaction_count']);
        $this->assertSame('Nekoyama', $row['artist_name']);
    }

    public function test_a_multi_product_sale_counts_once_in_the_distinct_total_and_each_product_gets_its_own_lines(): void
    {
        $a = $this->variant('Alpha');
        $b = $this->variant('Beta');
        $this->sell($a, 1, ['items' => [['variant_id' => $a->id, 'qty' => 2], ['variant_id' => $b->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 30000]]]);

        $report = $this->report();

        $this->assertSame(2, $this->row($report, 'Alpha')['units']);
        $this->assertSame(1, $this->row($report, 'Beta')['units']);
        $this->assertSame(1, $report['totals']['transaction_count'], 'one sale, even though it holds two products');
        $this->assertSame(3, $report['totals']['units']);
    }

    public function test_variants_break_down_without_changing_the_product_total(): void
    {
        $product = Product::factory()->create(['artist_id' => $this->artist->id, 'category_id' => $this->category->id, 'name' => 'Cats']);
        $v1 = $product->variants()->create(['sku' => 'CAT00001', 'variant_name' => 'Orange cat', 'sell_price' => 15000, 'cost_price' => 1, 'current_stock' => 100]);
        $v2 = $product->variants()->create(['sku' => 'CAT00002', 'variant_name' => 'Black cat', 'sell_price' => 15000, 'cost_price' => 1, 'current_stock' => 100]);
        $this->sell($v1, 4);
        $this->sell($v2, 1);

        $row = $this->row($this->report(), 'Cats');

        $this->assertSame(5, $row['units']);
        $this->assertSame(['Orange cat' => 4, 'Black cat' => 1], collect($row['variants'])->pluck('units', 'variant_name')->all());
        $this->assertSame($row['units'], collect($row['variants'])->sum('units'));
    }

    public function test_voided_sales_and_other_data_mode_are_excluded(): void
    {
        $p = $this->variant('Pins');
        $this->sell($p, 2);
        $voided = $this->sell($p, 5);
        app(OrderService::class)->void($voided, 'salah', $this->owner);
        $demo = $this->sell($p, 7);
        DB::table('orders')->where('id', $demo->id)->update(['data_mode' => 'demo']);
        DB::table('order_items')->where('order_id', $demo->id)->update(['data_mode' => 'demo']);

        $this->assertSame(2, $this->row($this->report(), 'Pins')['units']);
    }

    public function test_a_soft_deleted_product_with_history_still_appears(): void
    {
        $p = $this->variant('Retired');
        $this->sell($p, 2);
        $p->product->delete();

        $this->assertSame(2, $this->row($this->report(), 'Retired')['units']);
    }

    public function test_two_products_with_the_same_name_but_different_sellers_stay_separate(): void
    {
        $other = Artist::factory()->create(['name' => 'Yukishiro']);
        $this->sell($this->variant('Pins', artist: $this->artist), 1);
        $this->sell($this->variant('Pins', artist: $other), 4);

        $rows = collect($this->report()['data'])->where('product_name', 'Pins');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([1, 4], $rows->pluck('units')->all());
        $this->assertEqualsCanonicalizing(['Nekoyama', 'Yukishiro'], $rows->pluck('artist_name')->all());
    }

    public function test_event_and_date_filters_apply(): void
    {
        $p = $this->variant('Pins');
        $this->sell($p, 2);
        $otherEvent = Event::factory()->create(['status' => 'active']);
        $otherSession = CashierSession::factory()->create(['event_id' => $otherEvent->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        app(OrderService::class)->create(['session_id' => $otherSession->id, 'local_ref' => (string) Str::uuid(), 'items' => [['variant_id' => $p->id, 'qty' => 3]], 'payments' => [['method' => 'cash', 'amount' => 30000]]], $this->cashier);

        $this->assertSame(2, $this->row($this->report(), 'Pins')['units']);
        $all = $this->getJson('/api/v1/reports/sales-by-product')->assertOk()->json();
        $this->assertSame(5, $this->row($all, 'Pins')['units']);
        $this->assertSame([], $this->report(['date_from' => '2999-01-01'])['data']);
    }

    // --- terlaris ------------------------------------------------------------------

    public function test_the_top_three_by_units_are_best_sellers_and_ties_at_the_cutoff_are_all_marked(): void
    {
        foreach ([['A', 10], ['B', 5], ['C', 5], ['D', 2], ['E', 1]] as [$name, $qty]) {
            $this->sell($this->variant($name), $qty);
        }

        $report = $this->report();
        $marked = collect($report['data'])->where('is_best_seller', true)->pluck('product_name')->all();

        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $marked);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], collect($report['data'])->pluck('product_name')->sort()->values()->all());
        $this->assertSame(1, $this->row($report, 'A')['rank']);
        $this->assertSame(2, $this->row($report, 'B')['rank']);
        $this->assertSame(2, $this->row($report, 'C')['rank']);
        $this->assertSame(4, $this->row($report, 'D')['rank']);
        $this->assertSame('A', $report['data'][0]['product_name'], 'best seller first');
    }

    public function test_a_tie_beyond_third_place_marks_every_tied_product(): void
    {
        foreach ([['A', 10], ['B', 5], ['C', 5], ['D', 5], ['E', 1]] as [$name, $qty]) {
            $this->sell($this->variant($name), $qty);
        }

        $marked = collect($this->report()['data'])->where('is_best_seller', true)->pluck('product_name')->all();

        $this->assertEqualsCanonicalizing(['A', 'B', 'C', 'D'], $marked);
    }

    public function test_with_fewer_than_three_products_every_sold_product_is_a_best_seller(): void
    {
        $this->sell($this->variant('A'), 3);
        $this->sell($this->variant('B'), 1);

        $this->assertTrue(collect($this->report()['data'])->every(fn ($r) => $r['is_best_seller']));
    }

    // --- pencarian -----------------------------------------------------------------

    public function test_search_narrows_rows_and_totals_but_not_the_ranking(): void
    {
        foreach ([['Pins', 1], ['Stiker', 10], ['Poster', 8], ['Gantungan', 6]] as [$name, $qty]) {
            $this->sell($this->variant($name), $qty);
        }

        $report = $this->report(['q' => 'pin']);

        $this->assertSame(['Pins'], collect($report['data'])->pluck('product_name')->all());
        $this->assertSame(1, $report['totals']['units']);
        $this->assertFalse($report['data'][0]['is_best_seller'], 'searching must not crown a product that is not a best seller');
        $this->assertSame(4, $report['data'][0]['rank']);
    }

    public function test_search_also_matches_a_variant_name_and_treats_wildcards_literally(): void
    {
        $this->sell($this->variant('Cats', 'Orange cat'), 2);
        $this->sell($this->variant('Dogs', 'Good boy'), 1);

        $this->assertSame(['Cats'], collect($this->report(['q' => 'orange'])['data'])->pluck('product_name')->all());
        $this->assertSame([], $this->report(['q' => '%'])['data'], '% is not a wildcard');
        $this->assertSame([], $this->report(['q' => '_'])['data']);
    }

    // --- rincian transaksi ---------------------------------------------------------

    public function test_the_drill_down_lists_only_that_products_lines_per_sale_and_paginates(): void
    {
        $a = $this->variant('Alpha', price: 10000);
        $b = $this->variant('Beta', price: 5000);
        $this->sell($a, 1, ['items' => [['variant_id' => $a->id, 'qty' => 2], ['variant_id' => $b->id, 'qty' => 3]], 'payments' => [['method' => 'cash', 'amount' => 35000]]]);
        $this->sell($a, 4);
        $productId = $a->product_id;

        $res = $this->getJson("/api/v1/reports/sales-by-product/{$productId}/transactions?event_id={$this->event->id}&per_page=1")->assertOk()->json();

        $this->assertSame(2, $res['meta']['total']);
        $this->assertSame(2, $res['meta']['last_page']);
        $this->assertCount(1, $res['data']);

        $all = $this->getJson("/api/v1/reports/sales-by-product/{$productId}/transactions?event_id={$this->event->id}")->json('data');
        $this->assertEqualsCanonicalizing([2, 4], collect($all)->pluck('qty')->all());
        $this->assertEqualsCanonicalizing(['20000.00', '40000.00'], collect($all)->pluck('amount')->all());
        $this->assertNotNull($all[0]['order_number']);
        $this->assertSame($this->cashier->name, $all[0]['cashier_name']);
    }

    // --- akses & isi ---------------------------------------------------------------

    public function test_a_user_without_the_reports_menu_is_refused_everywhere(): void
    {
        $p = $this->variant('Pins');
        $this->sell($p, 1);
        $this->actingAs($this->cashier, 'sanctum');

        $this->getJson('/api/v1/reports/sales-by-product')->assertStatus(403);
        $this->getJson("/api/v1/reports/sales-by-product/{$p->product_id}/transactions")->assertStatus(403);
    }

    public function test_the_payload_never_carries_cost_or_margin(): void
    {
        $this->sell($this->variant('Pins'), 1);
        $json = json_encode($this->report());

        $this->assertStringNotContainsString('cost', $json);
        $this->assertStringNotContainsString('margin', $json);
        $this->assertStringNotContainsString('profit', $json);
    }

    public function test_an_empty_scope_returns_an_empty_list_with_zero_totals(): void
    {
        $report = $this->report();

        $this->assertSame([], $report['data']);
        $this->assertSame(0, $report['totals']['units']);
        $this->assertSame(0, $report['totals']['transaction_count']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_products(): void
    {
        $this->sell($this->variant('P0'), 1);
        $this->report(); // pemanasan cache pengaturan/lisensi
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->report();
        $few = count(DB::getQueryLog());

        foreach (range(1, 15) as $i) {
            $this->sell($this->variant("P{$i}"), 1);
        }
        DB::flushQueryLog();
        $this->report();
        $many = count(DB::getQueryLog());

        $this->assertSame($few, $many);
    }
}
