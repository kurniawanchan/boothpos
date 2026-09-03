<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 006-purchase-order-and-ops (US10) — laporan stok per artist di ReportController@stockByArtist.
 */
class StockByArtistReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_stock_totaled_per_artist(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create();
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 30]);
        $productA->variants()->create(['sku' => 'AAAKYAAA0002', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 20]);

        $artistB = Artist::factory()->create();
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 15]);

        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/reports/stock-by-artist');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('artist_id');

        $this->assertEquals(50, $rows[$artistA->id]['total_stock']);
        $this->assertEquals(2, $rows[$artistA->id]['variant_count']);
        $this->assertEquals(15, $rows[$artistB->id]['total_stock']);
    }

    public function test_an_artist_with_no_products_still_appears_with_zero_stock(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $artistWithNoProducts = Artist::factory()->create();

        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/v1/reports/stock-by-artist');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('artist_id');

        $this->assertEquals(0, $rows[$artistWithNoProducts->id]['variant_count']);
        $this->assertEquals(0, $rows[$artistWithNoProducts->id]['total_stock']);
    }

    public function test_stock_by_artist_report_filters_by_artist_id(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $category = Category::factory()->create();

        $artistA = Artist::factory()->create();
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 30]);

        $artistB = Artist::factory()->create();
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 15]);

        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson("/api/v1/reports/stock-by-artist?artist_id={$artistA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals($artistA->id, $response->json('data.0.artist_id'));
    }

    public function test_cashier_is_forbidden_from_the_stock_by_artist_report(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $response = $this->getJson('/api/v1/reports/stock-by-artist');

        $response->assertStatus(403);
    }
}
