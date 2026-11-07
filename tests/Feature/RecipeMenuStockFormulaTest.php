<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Support\RecipeCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 FR-002 (research D2) — angka stok menu yang TAMPIL dan angka bahan yang TERPOTONG harus berasal dari SATU
 * rumus. Dua salinan rumus pasti menyimpang suatu hari; tes ini mengunci bahwa `RecipeCost::perPortionNeed`
 * menghasilkan persis yang dipotong `ModifierMaterialConsumption::consumeOrder` saat pesanan selesai.
 */
class RecipeMenuStockFormulaTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function recipe(ProductVariant $variant, array $lines, float $yield = 1): Recipe
    {
        $recipe = Recipe::create([
            'product_id' => $variant->product_id, 'name' => 'Dasar', 'yield' => $yield,
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

    public function test_per_portion_need_is_quantity_over_shrinkage_over_yield_times_option_count(): void
    {
        $this->assertSame(1.0, RecipeCost::perPortionNeed(1, 0, 1));
        $this->assertEqualsWithDelta(0.625, RecipeCost::perPortionNeed(2, 20, 4), 1e-9);   // 2 ÷ 0,8 ÷ 4
        $this->assertEqualsWithDelta(1.875, RecipeCost::perPortionNeed(2, 20, 4, 3), 1e-9); // × 3 pilihan
        $this->assertEqualsWithDelta(0.5, RecipeCost::perPortionNeed(0.5, 0, 1), 1e-9);
    }

    public function test_displayed_need_equals_what_a_completed_sale_deducts(): void
    {
        // Susut 20%, Portions 4, jumlah pecahan: kombinasi yang paling mudah membuat dua rumus berbeda.
        $recipe = $this->recipe($this->nasiGoreng, ['cabai' => [2, 20], 'telur' => [0.5, 0]], yield: 4);

        $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated();

        foreach ($recipe->blocks->first()->lines as $line) {
            $key = array_search($line->material_id, array_map(fn ($m) => $m->id, $this->mat), true);
            $expected = round(RecipeCost::perPortionNeed((float) $line->quantity, (float) $line->shrinkage_pct, 4.0) * 3, 3);
            $deducted = 100.0 - $this->balance($key);
            $this->assertEqualsWithDelta($expected, $deducted, 0.0005, "bahan {$key}: rumus tampil harus = yang terpotong");
        }
    }
}
