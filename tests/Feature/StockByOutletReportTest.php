<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 006-purchase-order-and-ops (US10) — laporan stok per artist di ReportController@stockByOutlet.
 */
class StockByOutletReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 045 Stage 7 — laporan membaca saldo PER OUTLET. Varian yang dibuat langsung dengan `current_stock` dibukukan di
     * outlet bawaan; tes ini mengarakterisasi stok di outlet ASAL produk (seperti migrasi), jadi saldo dipindahkan ke sana.
     */
    private function placeStockAtHomeOutlets(): void
    {
        \Illuminate\Support\Facades\DB::table('variant_outlet_stock')->delete();
        foreach (\App\Models\ProductVariant::withoutGlobalScopes()->with('product')->get() as $v) {
            \Illuminate\Support\Facades\DB::table('variant_outlet_stock')->insert(['variant_id' => $v->id, 'outlet_id' => $v->product->home_outlet_id, 'current_stock' => $v->current_stock, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_owner_sees_stock_totaled_per_artist(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $category = Category::factory()->create();

        $artistA = Outlet::factory()->create();
        $productA = Product::factory()->create(['home_outlet_id' => $artistA->id, 'category_id' => $category->id]);
        $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 30]);
        $productA->variants()->create(['sku' => 'AAAKYAAA0002', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 20]);

        $artistB = Outlet::factory()->create();
        $productB = Product::factory()->create(['home_outlet_id' => $artistB->id, 'category_id' => $category->id]);
        $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 15]);

        $this->placeStockAtHomeOutlets();
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/reports/stock-by-outlet');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('outlet_id');

        $this->assertEquals(50, $rows[$artistA->id]['total_stock']);
        $this->assertEquals(2, $rows[$artistA->id]['variant_count']);
        $this->assertEquals(15, $rows[$artistB->id]['total_stock']);
    }

    public function test_an_artist_with_no_products_still_appears_with_zero_stock(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $artistWithNoProducts = Outlet::factory()->create();

        $this->placeStockAtHomeOutlets();
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/reports/stock-by-outlet');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('outlet_id');

        $this->assertEquals(0, $rows[$artistWithNoProducts->id]['variant_count']);
        $this->assertEquals(0, $rows[$artistWithNoProducts->id]['total_stock']);
    }

    public function test_cashier_is_forbidden_from_the_stock_by_outlet_report(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $response = $this->getJson('/api/v1/reports/stock-by-outlet');

        $response->assertStatus(403);
    }

    /**
     * 009-ui-ux-refinements (US7) — drilldown ?outlet_id= mengembalikan
     * baris tingkat variant untuk satu artist, bukan lagi array ringkasan
     * yang cuma difilter satu baris (perilaku lama, sekarang diganti).
     */
    public function test_stock_by_outlet_report_returns_variant_level_detail_when_artist_id_given(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $category = Category::factory()->create();

        $artistA = Outlet::factory()->create();
        $productA = Product::factory()->create(['home_outlet_id' => $artistA->id, 'category_id' => $category->id]);
        $variant1 = $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'variant_name' => 'Small', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 30]);
        $variant2 = $productA->variants()->create(['sku' => 'AAAKYAAA0002', 'variant_name' => 'Large', 'sell_price' => 12000, 'cost_price' => 5000, 'current_stock' => 20]);

        $artistB = Outlet::factory()->create();
        $productB = Product::factory()->create(['home_outlet_id' => $artistB->id, 'category_id' => $category->id]);
        $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 15]);

        $this->placeStockAtHomeOutlets();
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson("/api/v1/reports/stock-by-outlet?outlet_id={$artistA->id}");

        $response->assertOk();
        $response->assertJson([
            'outlet_id' => $artistA->id,
            'outlet_name' => $artistA->name,
            'variant_count' => 2,
            'total_stock' => 50,
        ]);
        $variants = collect($response->json('variants'))->keyBy('variant_id');
        $this->assertEquals('AAAKYAAA0001', $variants[$variant1->id]['sku']);
        $this->assertEquals(30, $variants[$variant1->id]['current_stock']);
        $this->assertEquals('AAAKYAAA0002', $variants[$variant2->id]['sku']);
        $this->assertEquals(20, $variants[$variant2->id]['current_stock']);
    }

    /** Regresi — tanpa home_outlet_id, bentuk respons ringkasan tetap tidak berubah. */
    public function test_stock_by_outlet_report_summary_shape_unchanged_when_artist_id_omitted(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $category = Category::factory()->create();

        $artistA = Outlet::factory()->create();
        $productA = Product::factory()->create(['home_outlet_id' => $artistA->id, 'category_id' => $category->id]);
        $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 30]);

        $this->placeStockAtHomeOutlets();
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/reports/stock-by-outlet');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['outlet_id', 'outlet_name', 'variant_count', 'total_stock']]]);
        $rows = collect($response->json('data'))->keyBy('outlet_id');
        $this->assertEquals(30, $rows[$artistA->id]['total_stock']);
        $this->assertEquals(1, $rows[$artistA->id]['variant_count']);
    }

    public function test_stock_by_outlet_report_404_for_nonexistent_artist_id(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->placeStockAtHomeOutlets();
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson('/api/v1/reports/stock-by-outlet?outlet_id=999999');

        $response->assertStatus(404);
    }

    public function test_stock_by_outlet_report_404_for_artist_id_in_other_data_mode(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $demoOutlet = \App\Support\ModeGate::runAs('demo', fn () => Outlet::factory()->create());

        $this->placeStockAtHomeOutlets();
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson("/api/v1/reports/stock-by-outlet?outlet_id={$demoOutlet->id}");

        $response->assertStatus(404);
    }
}
