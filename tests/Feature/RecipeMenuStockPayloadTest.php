<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 US1 + US4 (FR-015, FR-016, FR-018; contracts/api-changes.md §1) — payload menu POS: varian beresep membawa stok
 * menu di `outlet_stock`/`track_stock` (bidang yang SUDAH dibaca web dan APK lama) plus `stock_basis`/`stock_limiting`.
 * Varian tanpa resep tidak berubah kecuali `stock_basis: variant`.
 */
class RecipeMenuStockPayloadTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function recipe(ProductVariant $variant, array $lines): Recipe
    {
        $recipe = Recipe::create([
            'product_id' => $variant->product_id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $l) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $l[0], 'shrinkage_pct' => $l[1] ?? 0, 'display_order' => ++$i]);
        }

        return $recipe;
    }

    private function posVariant(int $variantId): array
    {
        $products = $this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk()->json('data');
        foreach ($products as $p) {
            foreach ($p['variants'] as $v) {
                if ($v['id'] === $variantId) {
                    return $v;
                }
            }
        }
        $this->fail("varian {$variantId} tidak ada di payload POS");
    }

    private function spec(): void
    {
        foreach (['soto' => 20, 'telur' => 10, 'cabai' => 500, 'ayam' => 2000] as $key => $qty) {
            $this->setStock($key, $this->outletA, $qty);
        }
        $this->recipe($this->nasiGoreng, ['soto' => [1], 'telur' => [1], 'cabai' => [50], 'ayam' => [200]]);
    }

    public function test_a_recipe_variant_carries_the_menu_stock_in_the_fields_clients_already_read(): void
    {
        $this->spec();

        $v = $this->posVariant($this->nasiGoreng->id);

        $this->assertSame(10, $v['outlet_stock']);
        $this->assertTrue($v['track_stock']);
        $this->assertSame('recipe', $v['stock_basis']);
        $this->assertSame($this->mat['ayam']->id, $v['stock_limiting']['material_id']);
        $this->assertSame('200.000', $v['stock_limiting']['per_portion']);
    }

    public function test_the_menu_stock_ignores_the_variant_stock_number(): void
    {
        $this->spec();
        ProductVariant::whereKey($this->nasiGoreng->id)->update(['current_stock' => 999]);

        $this->assertSame(10, $this->posVariant($this->nasiGoreng->id)['outlet_stock']);
    }

    public function test_a_zero_ingredient_shows_zero_and_the_card_can_be_blocked(): void
    {
        $this->spec();
        $this->setStock('telur', $this->outletA, 0);

        $v = $this->posVariant($this->nasiGoreng->id);

        $this->assertSame(0, $v['outlet_stock']);
        $this->assertTrue($v['track_stock'], 'angka 0 harus tampil sebagai habis, bukan "tanpa stok"');
    }

    public function test_the_number_updates_after_a_sale(): void
    {
        $this->spec();
        $this->assertSame(10, $this->posVariant($this->nasiGoreng->id)['outlet_stock']);

        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();

        $this->assertSame(8, $this->posVariant($this->nasiGoreng->id)['outlet_stock']);
    }

    public function test_an_unbounded_recipe_variant_has_no_number(): void
    {
        $this->recipe($this->nasiGoreng, ['telur' => [1]]);
        $this->mat['telur']->update(['is_active' => false]);

        $v = $this->posVariant($this->nasiGoreng->id);

        $this->assertNull($v['outlet_stock']);
        $this->assertFalse($v['track_stock']);
        $this->assertSame('recipe', $v['stock_basis']);
    }

    public function test_a_variant_without_a_recipe_keeps_its_old_fields(): void
    {
        $v = $this->posVariant($this->indomie->id);

        $this->assertSame('variant', $v['stock_basis']);
        $this->assertNull($v['stock_limiting']);
        $this->assertTrue($v['track_stock']);
        $this->assertIsInt($v['outlet_stock']);
    }

    public function test_variant_lookup_has_the_same_shape(): void
    {
        $this->spec();

        $row = collect($this->getJson('/api/v1/variants/lookup?pos=1&limit=50')->assertOk()->json('data'))->firstWhere('variant_id', $this->nasiGoreng->id);

        $this->assertSame(10, $row['outlet_stock']);
        $this->assertTrue($row['track_stock']);
        $this->assertSame('recipe', $row['stock_basis']);
        $this->assertSame($this->mat['ayam']->id, $row['stock_limiting']['material_id']);
    }

    public function test_the_management_payload_does_not_expose_menu_stock_but_marks_the_basis(): void
    {
        $this->spec();

        $products = $this->getJson('/api/v1/products?with_variants=1&per_page=100')->assertOk()->json('data');
        $v = collect($products)->flatMap(fn ($p) => $p['variants'])->firstWhere('id', $this->nasiGoreng->id);

        $this->assertNull($v['outlet_stock'], 'tanpa pos=1 tidak ada outlet pemesanan');
        $this->assertSame('recipe', $v['stock_basis']);
        $this->assertFalse($v['stock_tracked']);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_recipe_menus(): void
    {
        $this->spec();
        $few = $this->countQueries();

        for ($i = 0; $i < 12; $i++) {
            $product = Product::factory()->create(['name' => "Menu {$i}", 'home_outlet_id' => $this->outletA->id, 'category_id' => Category::factory()->create()->id]);
            $variant = $product->variants()->create(['sku' => strtoupper(Str::random(10)), 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);
            $this->recipe($variant, ['telur' => [1], 'cabai' => [10]]);
        }
        $many = $this->countQueries();

        $this->assertLessThanOrEqual($few + 3, $many, "kueri tumbuh menurut jumlah menu: {$few} → {$many}");
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }
}
