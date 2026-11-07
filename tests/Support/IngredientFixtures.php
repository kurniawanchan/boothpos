<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\VariantOutletStock;
use App\Services\StockService;

/**
 * 093 — dunia uji "produk sebagai bahan": dipakai bersama ModifierMaterialFixtures (outlet A, kasir, shift, `checkout`).
 * Bahan produk `mie` (Indomie Goreng) dan `telur` bertanda bahan dengan saldo di outlet A; `nasiGoreng` adalah menunya.
 */
trait IngredientFixtures
{
    /** @var array<string, ProductVariant> */
    protected array $ing = [];

    private int $ingSeq = 0;

    /** @param array<string, int> $stock key => saldo awal di outlet A */
    protected function buildIngredientWorld(array $stock = ['mie' => 100, 'telur' => 200]): void
    {
        foreach (['mie' => 'Indomie Goreng', 'telur' => 'Telur'] as $key => $name) {
            $this->ing[$key] = $this->makeIngredient($name, $stock[$key] ?? 0);
        }
    }

    protected function makeIngredient(string $name, int $stockA = 0, array $product = []): ProductVariant
    {
        $p = Product::factory()->create(array_merge([
            'name' => $name, 'home_outlet_id' => $this->outletA->id, 'category_id' => Category::factory()->create()->id,
            'use_as_ingredient' => true, 'ingredient_unit' => 'pcs',
        ], $product));
        $v = $p->variants()->create(['sku' => 'IG'.++$this->ingSeq.substr(md5($name), 0, 4), 'variant_name' => 'Standard', 'sell_price' => 3000, 'cost_price' => 1000, 'current_stock' => 0]);
        if ($stockA > 0) {
            app(StockService::class)->applyMovement($v, $this->outletA->id, 'adjustment', $stockA);
        }

        return $v;
    }

    /**
     * Resep dasar pada $menu: baris produk `[key => qty]` dan opsional baris bahan baku `['m:soto' => qty]`.
     *
     * @param  array<string, float|int>  $lines
     */
    protected function ingredientRecipe(ProductVariant $menu, array $lines, float $yield = 1, ?int $modifierOptionId = null, string $name = 'Dasar'): Recipe
    {
        $recipe = Recipe::create([
            'product_id' => $menu->product_id, 'name' => $name, 'yield' => $yield,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        $recipe->variants()->sync(array_unique(array_merge($recipe->variants()->pluck('product_variants.id')->all(), [$menu->id])));
        $block = $recipe->blocks()->create(['variant_id' => $menu->id, 'modifier_option_id' => $modifierOptionId, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $qty) {
            $row = ['recipe_id' => $recipe->id, 'quantity' => $qty, 'shrinkage_pct' => 0, 'display_order' => ++$i];
            if (str_starts_with((string) $key, 'm:')) {
                $row['material_id'] = $this->mat[substr($key, 2)]->id;
            } else {
                $row['variant_id'] = $this->ing[$key]->id;
            }
            $block->lines()->create($row);
        }

        return $recipe;
    }

    protected function vBalance(string|ProductVariant $v, ?\App\Models\Outlet $outlet = null): int
    {
        $variant = is_string($v) ? $this->ing[$v] : $v;

        return (int) VariantOutletStock::where(['variant_id' => $variant->id, 'outlet_id' => ($outlet ?? $this->outletA)->id])->value('current_stock');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, StockMovement> */
    protected function recipeMoves(string|ProductVariant $v, ?array $types = null)
    {
        $variant = is_string($v) ? $this->ing[$v] : $v;

        return StockMovement::where('variant_id', $variant->id)->whereIn('type', $types ?? StockMovement::RECIPE_TYPES)->orderBy('id')->get();
    }
}
