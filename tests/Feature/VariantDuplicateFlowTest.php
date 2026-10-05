<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 037-variant-drawer-bom-ui (US2) — "Duplikat varian" TIDAK punya endpoint sendiri: kartu salinan
 * disimpan lewat alur varian-baru yang sudah ada (POST /products/{id}/variants dengan
 * copy_bom_from_variant_id). Tes ini mengunci jaminan yang dipakai fitur itu: salinan dari sumber
 * yang BOM-nya SELESAI tidak ikut selesai, harga modal yang dikirim tidak ditimpa, SKU baru milik
 * server, BOM salinan mandiri, dan izin menu tetap ditegakkan di server.
 */
class VariantDuplicateFlowTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    private ProductVariant $red;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();

        $this->red = $this->variantFor($this->seller);
        [$chain] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        [$assembly] = $this->po($this->seller, 'received', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]]);
        $this->addItems($this->red, [['purchase_order_item_id' => $chain->id, 'qty' => 2], ['purchase_order_item_id' => $assembly->id]])->assertCreated();
        $this->postJson("/api/v1/variants/{$this->red->id}/bom/complete")->assertOk(); // 2*500 + 1000 = 2000
    }

    private function duplicate(array $extra = [])
    {
        return $this->postJson("/api/v1/products/{$this->red->product_id}/variants", [
            'variant_name' => 'Red (salinan)', 'sell_price' => 5000, 'cost_price' => 2000, 'low_stock_alert' => 3,
            'copy_bom_from_variant_id' => $this->red->id,
        ] + $extra);
    }

    public function test_a_duplicate_of_a_complete_variant_gets_the_bom_rows_but_is_not_complete_and_keeps_the_sent_cost_price(): void
    {
        $created = $this->duplicate()->assertCreated();

        $copy = ProductVariant::findOrFail($created->json('id'));
        $this->assertNotSame($this->red->sku, $copy->sku, 'the server generates a NEW sku');
        $this->assertFalse((bool) $copy->bom_complete);
        $this->assertSame('2000.00', number_format((float) $copy->cost_price, 2, '.', ''), 'the cost price sent by the client is not overwritten');
        $this->assertSame('Red (salinan)', $copy->variant_name);
        $this->assertSame(3, (int) $copy->low_stock_alert);

        $rows = $this->getJson("/api/v1/variants/{$copy->id}/bom")->assertOk();
        $this->assertCount(2, $rows->json('data'));
        $this->assertFalse($rows->json('summary.bom_complete'));
        $this->assertSame('2000.00', $rows->json('summary.bom_cost'));
    }

    public function test_the_copy_is_independent_and_the_complete_source_is_untouched(): void
    {
        $copy = ProductVariant::findOrFail($this->duplicate()->assertCreated()->json('id'));
        $copyRow = $this->getJson("/api/v1/variants/{$copy->id}/bom")->json('data.0');
        $sourceBefore = $this->getJson("/api/v1/variants/{$this->red->id}/bom")->json();

        $this->putJson("/api/v1/bom/{$copyRow['id']}", ['qty_needed' => 9])->assertOk();

        $sourceAfter = $this->getJson("/api/v1/variants/{$this->red->id}/bom")->json();
        $this->assertSame($sourceBefore['data'], $sourceAfter['data']);
        $this->assertTrue($sourceAfter['summary']['bom_complete']);
        $this->assertSame('2000.00', $sourceAfter['summary']['cost_price']);
    }

    public function test_copying_the_bom_while_creating_a_variant_needs_the_purchase_orders_menu_and_leaves_nothing_behind(): void
    {
        $productsOnly = User::factory()->create(['role_id' => Role::factory()->create(['name' => 'Staf Produk', 'menu_keys' => ['products']])->id]);
        $this->actingAs($productsOnly, 'sanctum');
        $before = ProductVariant::where('product_id', $this->red->product_id)->count();

        $this->duplicate()->assertForbidden();

        $this->assertSame($before, ProductVariant::where('product_id', $this->red->product_id)->count());
        // Tanpa salinan BOM, varian tetap bisa dibuat (jalur "hanya kolom" milik pengguna tanpa menu PO).
        $this->postJson("/api/v1/products/{$this->red->product_id}/variants", ['variant_name' => 'Plain', 'sell_price' => 1000])->assertCreated();
    }
}
