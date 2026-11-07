<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 093 (Foundational, data-model.md) — skema "produk sebagai bahan": dua kolom di `products`, `recipe_lines.variant_id`
 * dengan CHECK "tepat satu bahan", dan tiga tipe pergerakan stok baru. Tanpa tabel saldo baru (FR-012).
 */
class IngredientSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function recipeBlockId(): array
    {
        $user = User::factory()->create(['role' => 'owner']);
        $product = Product::factory()->create();
        $recipe = Recipe::create([
            'product_id' => $product->id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->toDateString(), 'created_by' => $user->id,
        ]);
        $variant = $product->variants()->create(['sku' => 'MENU-1', 'variant_name' => 'Standard', 'sell_price' => 1000, 'cost_price' => 500, 'current_stock' => 0]);
        $blockId = DB::table('recipe_blocks')->insertGetId(['recipe_id' => $recipe->id, 'variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);

        return [$recipe->id, $blockId];
    }

    private function line(int $recipeId, int $blockId, ?int $materialId, ?int $variantId): void
    {
        DB::table('recipe_lines')->insert([
            'recipe_id' => $recipeId, 'block_id' => $blockId, 'material_id' => $materialId, 'variant_id' => $variantId,
            'quantity' => 1, 'shrinkage_pct' => 0, 'display_order' => 1,
        ]);
    }

    public function test_products_get_the_ingredient_flag_off_by_default_and_an_optional_unit(): void
    {
        $this->assertTrue(Schema::hasColumns('products', ['use_as_ingredient', 'ingredient_unit']));

        $product = Product::factory()->create();
        $fresh = Product::findOrFail($product->id);
        $this->assertFalse($fresh->use_as_ingredient);
        $this->assertNull($fresh->ingredient_unit);
    }

    public function test_a_recipe_line_points_to_exactly_one_of_material_or_variant(): void
    {
        [$recipeId, $blockId] = $this->recipeBlockId();
        $material = Material::factory()->create();
        $ingredient = Product::factory()->create()->variants()->create(['sku' => 'TLR-1', 'variant_name' => 'Standard', 'sell_price' => 2000, 'cost_price' => 1000, 'current_stock' => 0]);

        $this->line($recipeId, $blockId, $material->id, null);   // bahan baku: lolos
        $this->line($recipeId, $blockId, null, $ingredient->id); // produk bahan: lolos
        $this->assertSame(2, DB::table('recipe_lines')->where('recipe_id', $recipeId)->count());

        foreach ([[$material->id, $ingredient->id], [null, null]] as [$m, $v]) {
            try {
                $this->line($recipeId, $blockId, $m, $v);
                $this->fail('CHECK harus menolak baris dengan keduanya terisi atau keduanya kosong.');
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('chk_recipe_lines_one_ingredient', $e->getMessage());
            }
        }
    }

    public function test_the_ingredient_variant_cannot_be_deleted_while_a_recipe_line_references_it(): void
    {
        [$recipeId, $blockId] = $this->recipeBlockId();
        $ingredient = Product::factory()->create()->variants()->create(['sku' => 'TLR-2', 'variant_name' => 'Standard', 'sell_price' => 2000, 'cost_price' => 1000, 'current_stock' => 0]);
        $this->line($recipeId, $blockId, null, $ingredient->id);

        $this->expectException(QueryException::class);
        DB::table('product_variants')->where('id', $ingredient->id)->delete(); // restrictOnDelete
    }

    public function test_stock_movements_accept_the_three_recipe_types(): void
    {
        $variant = Product::factory()->create()->variants()->create(['sku' => 'TLR-3', 'variant_name' => 'Standard', 'sell_price' => 2000, 'cost_price' => 1000, 'current_stock' => 0]);
        $outletId = \App\Models\Outlet::factory()->create()->id;

        foreach (['recipe_sale' => -1, 'recipe_return' => 1, 'recipe_waste' => -1] as $type => $delta) {
            DB::table('stock_movements')->insert([
                'variant_id' => $variant->id, 'outlet_id' => $outletId, 'type' => $type, 'qty_change' => $delta,
                'stock_before' => 5, 'stock_after' => 5 + $delta, 'reference_type' => 'order_item', 'reference_id' => 1,
                'created_at' => now(), 'data_mode' => 'live',
            ]);
        }

        $this->assertSame(3, DB::table('stock_movements')->where('variant_id', $variant->id)->count());
    }

    public function test_existing_material_lines_stay_valid_and_no_stock_table_is_added(): void
    {
        [$recipeId, $blockId] = $this->recipeBlockId();
        $this->line($recipeId, $blockId, Material::factory()->create()->id, null);

        $this->assertNull(DB::table('recipe_lines')->where('recipe_id', $recipeId)->value('variant_id'));
        $this->assertFalse(Schema::hasTable('ingredient_stock'), 'Satu saldo per varian per outlet: tidak ada tabel saldo baru.');
        $this->assertTrue(Schema::hasTable('variant_outlet_stock'));
    }
}
