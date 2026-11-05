<?php

namespace Tests\Feature\SellerScope;

use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 058 US3 — pengguna ber-seller tidak bisa membuat/mengubah/menghapus data seller lain, juga lewat permintaan buatan. */
class CrossSellerWriteTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    private function productPayload(int $artistId, int $categoryId): array
    {
        return [
            'artist_id' => $artistId, 'category_id' => $categoryId, 'name' => 'Produk Baru',
            'variants' => [['variant_name' => 'Std', 'sell_price' => 5000]],
        ];
    }

    public function test_product_create_is_forced_to_the_own_seller(): void
    {
        $w = $this->buildSellerWorld();
        $cat = Category::first();
        $this->actingAsSellerUser($w['a'], 'admin');

        $ok = $this->postJson('/api/v1/products', $this->productPayload($w['a']->id, $cat->id))->assertCreated();
        $this->assertSame($w['a']->id, $ok->json('artist.id') ?? $ok->json('artist_id'));

        // artist_id dihilangkan -> default seller sendiri.
        $payload = $this->productPayload($w['a']->id, $cat->id);
        unset($payload['artist_id']);
        $payload['name'] = 'Zebra Kedua'; // segmen kode berbeda dari produk pertama
        $this->postJson('/api/v1/products', $payload)->assertCreated();

        // seller lain -> ditolak, tidak ada produk dibuat.
        $before = \App\Models\Product::withoutGlobalScopes()->count();
        $this->postJson('/api/v1/products', $this->productPayload($w['b']->id, $cat->id))->assertStatus(422)->assertJsonValidationErrors('artist_id');
        $this->assertSame($before, \App\Models\Product::withoutGlobalScopes()->count());
    }

    public function test_product_and_variant_edits_on_a_foreign_product_are_not_found_and_change_nothing(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $this->putJson("/api/v1/products/{$w['productB']->id}", ['name' => 'Diretas'])->assertNotFound();
        $this->deleteJson("/api/v1/products/{$w['productB']->id}")->assertNotFound();
        $this->postJson("/api/v1/products/{$w['productB']->id}/variants", ['variant_name' => 'X', 'sell_price' => 1])->assertNotFound();
        $this->putJson("/api/v1/variants/{$w['variantB']->id}", ['variant_name' => 'Diretas'])->assertNotFound();
        $this->deleteJson("/api/v1/variants/{$w['variantB']->id}")->assertNotFound();

        $this->assertSame('Produk Beta Unik', \App\Models\Product::withoutGlobalScopes()->find($w['productB']->id)->name);
        $this->assertNotNull(ProductVariant::withoutGlobalScopes()->find($w['variantB']->id));
    }

    public function test_changing_a_products_seller_to_another_seller_is_refused(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $this->putJson("/api/v1/products/{$w['productA']->id}", ['artist_id' => $w['b']->id])->assertStatus(422);
        $this->assertSame($w['a']->id, \App\Models\Product::withoutGlobalScopes()->find($w['productA']->id)->artist_id);
    }

    public function test_stock_adjustment_on_a_foreign_variant_is_not_found_and_writes_no_movement(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');
        $before = StockMovement::withoutGlobalScopes()->count();

        $this->postJson('/api/v1/stock/adjustments', [
            'items' => [['variant_id' => $w['variantB']->id, 'qty_change' => 5]], 'reason' => 'uji',
        ])->assertStatus(404);

        $this->assertSame($before, StockMovement::withoutGlobalScopes()->count());
        $this->assertSame(48, ProductVariant::withoutGlobalScopes()->find($w['variantB']->id)->current_stock);
    }

    public function test_selling_or_preordering_a_foreign_variant_writes_nothing(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');
        $orders = \App\Models\Order::withoutGlobalScopes()->count();
        $preorders = \App\Models\Preorder::withoutGlobalScopes()->count();

        $this->postJson('/api/v1/orders', [
            'session_id' => $w['session']->id,
            'items' => [['variant_id' => $w['variantB']->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertStatus(422); // varian seller lain tak ditemukan (validasi), tak ada yang tertulis
        $pre = $this->postJson('/api/v1/preorders', [
            'customer_id' => $w['customerA']->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $w['variantB']->id, 'qty' => 1]],
        ]);
        $this->assertContains($pre->status(), [404, 422]);

        $this->assertSame($orders, \App\Models\Order::withoutGlobalScopes()->count());
        $this->assertSame($preorders, \App\Models\Preorder::withoutGlobalScopes()->count());
        $this->assertSame(48, ProductVariant::withoutGlobalScopes()->find($w['variantB']->id)->current_stock);
    }

    public function test_bom_copy_from_a_foreign_variant_is_not_found(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $res = $this->postJson("/api/v1/variants/{$w['variantA']->id}/bom/copy", ['source_variant_id' => $w['variantB']->id]);
        $this->assertContains($res->status(), [404, 422]);
    }

    public function test_purchase_order_seller_is_forced_to_the_own_seller(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');
        $vendor = Vendor::factory()->create();
        $payload = fn (?int $artistId) => array_filter([
            'vendor_id' => $vendor->id, 'artist_id' => $artistId,
            'items' => [['line_type' => 'service', 'description' => 'Jasa', 'qty' => 1, 'unit_price' => 1000]],
        ], fn ($v) => $v !== null);

        $po = $this->postJson('/api/v1/purchase-orders', $payload(null))->assertCreated();
        $this->assertSame($w['a']->id, \App\Models\PurchaseOrder::withoutGlobalScopes()->find($po->json('id'))->artist_id);

        $this->postJson('/api/v1/purchase-orders', $payload($w['b']->id))->assertStatus(422)->assertJsonValidationErrors('artist_id');
    }

    public function test_settlement_preview_and_store_for_another_seller_are_refused(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');
        $event = $w['session']->event_id;

        $this->getJson("/api/v1/settlements/preview?event_id={$event}&artist_id={$w['b']->id}")->assertNotFound();
        $res = $this->postJson('/api/v1/settlements', ['event_id' => $event, 'artist_id' => $w['b']->id]);
        $this->assertContains($res->status(), [404, 422]);
        $this->assertSame(0, \App\Models\SellerSettlement::withoutGlobalScopes()->where('artist_id', $w['b']->id)->count());
    }
}
