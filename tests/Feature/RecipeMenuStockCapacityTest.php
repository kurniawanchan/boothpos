<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Services\MenuStockService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 US1 (FR-001..FR-004, SC-001, SC-008) — stok menu = kapasitas bahan TERENDAH. Dunia uji: 'soto' (Indomie),
 * 'telur', 'cabai' (Sayur), 'ayam' (Kuah). Id bahan naik menurut urutan pembuatan: soto < ayam < telur < cabai.
 */
class RecipeMenuStockCapacityTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function recipe(ProductVariant $variant, array $lines, float $yield = 1, string $name = 'Dasar', array $over = []): Recipe
    {
        $recipe = Recipe::create(array_merge([
            'product_id' => $variant->product_id, 'name' => $name, 'yield' => $yield,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ], $over));
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $l) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $l[0], 'shrinkage_pct' => $l[1] ?? 0, 'display_order' => ++$i]);
        }

        return $recipe;
    }

    private function stock(?ProductVariant $v = null, ?Outlet $outlet = null): ?array
    {
        $v ??= $this->nasiGoreng;

        return app(MenuStockService::class)->forVariants([$v->id], ($outlet ?? $this->outletA)->id)[$v->id] ?? null;
    }

    private function spec(): void
    {
        foreach (['soto' => 20, 'telur' => 10, 'cabai' => 500, 'ayam' => 2000] as $key => $qty) {
            $this->setStock($key, $this->outletA, $qty);
        }
        $this->recipe($this->nasiGoreng, ['soto' => [1], 'telur' => [1], 'cabai' => [50], 'ayam' => [200]]);
    }

    public function test_the_spec_example_is_limited_by_the_bottleneck_ingredient(): void
    {
        $this->spec();

        $s = $this->stock();

        $this->assertSame(10, $s['portions']);
        // Tiga bahan seri di 10: penghambat deterministik = id terkecil (ayam < telur < cabai).
        $this->assertSame($this->mat['ayam']->id, $s['limiting']['material_id']);
        $this->assertSame('2000.000', $s['limiting']['on_hand']);
        $this->assertSame('200.000', $s['limiting']['per_portion']);
    }

    public function test_the_lowest_capacity_ingredient_decides(): void
    {
        $this->spec();
        $this->setStock('telur', $this->outletA, 3);

        $s = $this->stock();

        $this->assertSame(3, $s['portions']);
        $this->assertSame($this->mat['telur']->id, $s['limiting']['material_id']);
        $this->assertSame('3.000', $s['limiting']['on_hand']);
        $this->assertSame('1.000', $s['limiting']['per_portion']);
    }

    public function test_a_zero_ingredient_makes_the_menu_unavailable(): void
    {
        $this->spec();
        $this->setStock('telur', $this->outletA, 0);

        $this->assertSame(0, $this->stock()['portions']);
    }

    public function test_a_negative_legacy_balance_reads_as_zero_not_negative(): void
    {
        $this->spec();
        MaterialOutletStock::query()->where(['material_id' => $this->mat['telur']->id, 'outlet_id' => $this->outletA->id])->update(['current_stock' => -3]);

        $this->assertSame(0, $this->stock()['portions']);
    }

    public function test_an_ingredient_without_a_balance_row_in_the_outlet_counts_as_zero(): void
    {
        $this->spec();
        $outletC = Outlet::factory()->create(['code' => 'WJC', 'name' => 'Warkop C']);

        $this->assertSame(0, $this->stock(outlet: $outletC)['portions']);
    }

    public function test_each_outlet_uses_its_own_balances(): void
    {
        $this->spec();
        $this->setStock('telur', $this->outletB, 4);
        $this->setStock('soto', $this->outletB, 100);
        $this->setStock('cabai', $this->outletB, 1000);
        $this->setStock('ayam', $this->outletB, 4000);

        $this->assertSame(10, $this->stock(outlet: $this->outletA)['portions']);
        $this->assertSame(4, $this->stock(outlet: $this->outletB)['portions']);
    }

    public function test_shrinkage_and_portions_scale_the_need(): void
    {
        $this->setStock('cabai', $this->outletA, 100);
        // 2 gr ÷ 0,8 ÷ 4 = 0,625 gr per porsi → 100 ÷ 0,625 = 160.
        $this->recipe($this->nasiGoreng, ['cabai' => [2, 20]], yield: 4);

        $s = $this->stock();

        $this->assertSame(160, $s['portions']);
        $this->assertSame('0.625', $s['limiting']['per_portion']);
    }

    public function test_fractional_quantities_do_not_lose_a_portion_to_binary_rounding(): void
    {
        // 0,3 ÷ 0,1 = 2,9999… dalam pecahan biner; harus tetap 3 porsi.
        $this->setStock('telur', $this->outletA, 0.3);
        $this->recipe($this->nasiGoreng, ['telur' => [0.1]]);

        $this->assertSame(3, $this->stock()['portions']);
    }

    public function test_the_result_is_floored_never_rounded_up(): void
    {
        $this->setStock('telur', $this->outletA, 9.9);
        $this->recipe($this->nasiGoreng, ['telur' => [2]]);

        $this->assertSame(4, $this->stock()['portions']);
    }

    public function test_all_active_recipes_of_a_variant_are_summed_before_dividing(): void
    {
        $this->setStock('telur', $this->outletA, 100);
        $this->recipe($this->nasiGoreng, ['telur' => [1]], name: 'Dasar');
        $this->recipe($this->nasiGoreng, ['telur' => [1]], name: 'Topping');

        $this->assertSame(50, $this->stock()['portions'], 'bahan sama di dua resep digabung: 2 per porsi');
    }

    public function test_future_and_archived_recipes_do_not_make_a_menu_recipe_driven(): void
    {
        $this->recipe($this->nasiGoreng, ['telur' => [1]], name: 'Masa depan', over: ['effective_from' => now()->addDays(3)->toDateString()]);
        $this->recipe($this->nasiGoreng, ['telur' => [1]], name: 'Arsip', over: ['archived_at' => now()]);

        $this->assertNull($this->stock(), 'tanpa resep aktif: tidak ada di hasil, pemanggil memakai stok varian');
    }

    public function test_inactive_ingredients_are_skipped_and_all_inactive_means_unbounded(): void
    {
        $this->setStock('telur', $this->outletA, 6);
        $this->recipe($this->nasiGoreng, ['telur' => [1], 'cabai' => [100]]);
        $this->mat['cabai']->update(['is_active' => false]);

        $this->assertSame(6, $this->stock()['portions'], 'cabai nonaktif tidak membatasi, sama seperti saat dipotong');

        $this->mat['telur']->update(['is_active' => false]);
        $s = $this->stock();
        $this->assertNull($s['portions']);
        $this->assertNull($s['limiting']);
    }

    public function test_a_trashed_ingredient_is_skipped(): void
    {
        $this->setStock('telur', $this->outletA, 6);
        $this->recipe($this->nasiGoreng, ['telur' => [1], 'cabai' => [100]]);
        Material::query()->whereKey($this->mat['cabai']->id)->first()->delete();

        $this->assertSame(6, $this->stock()['portions']);
    }

    public function test_a_variant_without_a_recipe_is_not_in_the_result(): void
    {
        $this->recipe($this->nasiGoreng, ['telur' => [1]]);

        $r = app(MenuStockService::class)->forVariants([$this->nasiGoreng->id, $this->indomie->id], $this->outletA->id);

        $this->assertArrayHasKey($this->nasiGoreng->id, $r);
        $this->assertArrayNotHasKey($this->indomie->id, $r);
    }

    public function test_demo_and_live_recipes_are_separate(): void
    {
        $this->recipe($this->nasiGoreng, ['telur' => [1]]);

        $this->assertNotNull($this->stock());
        ModeGate::runAs('demo', function () {
            $this->assertNull($this->stock(), 'resep LIVE tidak terlihat di DEMO');
        });
        $this->assertNotNull($this->stock(), 'kembali ke LIVE, memo tidak bocor lintas mode');
    }
}
