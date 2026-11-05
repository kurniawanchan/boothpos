<?php

namespace Tests\Feature\SellerScope;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 058 US2 — katalog, varian, stok, riwayat stok dan BOM hanya milik seller pengguna; milik seller lain = 404. */
class CatalogStockReadTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    public function test_products_variants_stock_and_catalog_show_only_own_seller(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $ids = collect($this->getJson('/api/v1/products?with_variants=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$w['productA']->id], $ids);

        $this->getJson("/api/v1/products/{$w['productA']->id}")->assertOk();
        $this->getJson("/api/v1/products/{$w['productB']->id}")->assertNotFound();

        $lookup = collect($this->getJson('/api/v1/variants/lookup?q='.urlencode($w['variantB']->sku))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($w['variantB']->id, $lookup);
        $exact = $this->getJson('/api/v1/variants/lookup?exact=1&q='.urlencode($w['variantB']->sku))->assertOk()->json('data');
        $this->assertSame([], $exact);
        $own = $this->getJson('/api/v1/variants/lookup?exact=1&q='.urlencode($w['variantA']->sku))->assertOk()->json('data');
        $this->assertCount(1, $own);

        $catalog = $this->getJson('/api/v1/catalog')->assertOk()->getContent();
        $this->assertStringContainsString($w['variantA']->sku, $catalog);
        $this->assertStringNotContainsString($w['variantB']->sku, $catalog);
    }

    public function test_stock_movements_and_low_stock_show_only_own_seller(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $variantIds = collect($this->getJson('/api/v1/stock/movements?per_page=100')->assertOk()->json('data'))->pluck('variant_id')->unique()->values()->all();
        $this->assertSame([$w['variantA']->id], $variantIds);

        $low = $this->getJson('/api/v1/stock/low')->assertOk()->getContent();
        $this->assertStringNotContainsString($w['variantB']->sku, $low);
    }

    public function test_foreign_variant_bom_and_edits_are_not_found(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $this->getJson("/api/v1/variants/{$w['variantB']->id}/bom")->assertNotFound();
        $this->getJson("/api/v1/variants/{$w['variantB']->id}/bom/eligible-lines")->assertNotFound();
        $this->putJson("/api/v1/variants/{$w['variantB']->id}", ['name' => 'x'])->assertNotFound();
    }

    public function test_a_user_without_a_seller_still_sees_everything(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']), 'sanctum');

        $ids = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$w['productA']->id, $w['productB']->id], $ids);
    }
}
