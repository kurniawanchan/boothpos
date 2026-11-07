<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\VariantOutletStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 US5 (FR-006, SC-006) — menu TANPA resep aktif memakai stok varian persis seperti sebelum fitur ini: potong saat
 * baris masuk, kembali saat dihapus/void, ditolak bila kurang; payload POS hanya mendapat bidang tambahan.
 */
class RecipeMenuStockBackwardCompatTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function variantBalance(ProductVariant $v): int
    {
        return (int) VariantOutletStock::query()->where('variant_id', $v->id)->where('outlet_id', $this->outletA->id)->value('current_stock');
    }

    /** Lewat StockService (satu-satunya penulis stok varian): menulis `current_stock` langsung memicu sinkronisasi outlet dan merusak saldo. */
    private function setVariantStock(ProductVariant $v, int $qty): void
    {
        $delta = $qty - $this->variantBalance($v);
        if ($delta !== 0) {
            app(\App\Services\StockService::class)->applyMovement($v, $this->outletA->id, 'adjustment', $delta);
        }
    }

    public function test_variant_stock_is_still_deducted_at_checkout_and_refused_when_short(): void
    {
        $this->setVariantStock($this->nasiGoreng, 5);

        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();
        $this->assertSame(3, $this->variantBalance($this->nasiGoreng));

        $refused = $this->checkout([[$this->nasiGoreng, 4, []]]);
        $this->assertContains($refused->status(), [409, 422], 'ditolak seperti sebelum fitur ini (bukan 201, bukan kode resep)');
        $this->assertNotSame('insufficient_recipe_stock', $refused->json('code'));
        $this->assertSame(3, $this->variantBalance($this->nasiGoreng), 'penolakan tidak meninggalkan pergerakan');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_tab_line_deducts_on_add_and_returns_on_remove_exactly_as_before(): void
    {
        $this->setVariantStock($this->nasiGoreng, 10);
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $tabId = $tab['id'] ?? $tab['data']['id'];

        $this->apiAddLine($tabId, $this->nasiGoreng->id, 3)->assertSuccessful();
        $this->assertSame(7, $this->variantBalance($this->nasiGoreng));

        $line = OrderItem::query()->where('order_id', $tabId)->value('id');
        $this->deleteJson("/api/v1/tabs/{$tabId}/items/{$line}")->assertSuccessful();
        $this->assertSame(10, $this->variantBalance($this->nasiGoreng));
    }

    public function test_the_pos_payload_of_a_non_recipe_menu_only_gains_the_basis_field(): void
    {
        $this->setVariantStock($this->nasiGoreng, 7);

        $products = $this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk()->json('data');
        $v = collect($products)->flatMap(fn ($p) => $p['variants'])->firstWhere('id', $this->nasiGoreng->id);

        $this->assertSame(7, $v['outlet_stock']);
        $this->assertSame(7, $v['current_stock']);
        $this->assertTrue($v['track_stock']);
        $this->assertTrue($v['stock_tracked']);
        $this->assertSame('variant', $v['stock_basis']);
        $this->assertNull($v['stock_limiting']);
    }

    public function test_archiving_a_recipe_hands_the_menu_back_to_its_variant_stock(): void
    {
        $recipe = Recipe::create(['product_id' => $this->nasiGoreng->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id]);
        $recipe->variants()->sync([$this->nasiGoreng->id]);
        $block = $recipe->blocks()->create(['variant_id' => $this->nasiGoreng->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat['telur']->id, 'quantity' => 1, 'shrinkage_pct' => 0, 'display_order' => 1]);
        $this->setVariantStock($this->nasiGoreng, 6);

        $recipe->update(['archived_at' => now()]);

        $products = $this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk()->json('data');
        $v = collect($products)->flatMap(fn ($p) => $p['variants'])->firstWhere('id', $this->nasiGoreng->id);
        $this->assertSame('variant', $v['stock_basis']);
        $this->assertSame(6, $v['outlet_stock'], 'kembali ke stok varian; angka lama dilanjutkan, tidak ditulis ulang');
    }

    public function test_a_modifier_sourced_product_without_a_recipe_is_unchanged(): void
    {
        Product::query()->whereKey($this->nasiGoreng->product_id)->update(['stock_source' => 'modifier']);
        $before = StockMovement::query()->count();

        $r = $this->checkout([[$this->nasiGoreng->fresh(), 2, []]])->assertCreated();

        $this->assertSame($before, StockMovement::query()->count(), '087: sumber modifier tidak menggerakkan stok varian');
        $this->assertSame('modifier', OrderItem::find($r->json('items.0.id'))->stock_source);
    }
}
