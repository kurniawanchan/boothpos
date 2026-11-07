<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Support\IngredientVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 093 (research D5/D8) — `IngredientVariants`: himpunan varian yang dirujuk baris resep TIDAK-arsip. Dimuat sekali per
 * permintaan (Konstitusi V), dibuang oleh setiap penulis resep/baris, dan mencakup resep berlaku-masa-depan karena
 * dipakai untuk menjaga (validasi + tahanan), bukan untuk memotong stok.
 */
class IngredientVariantsMemoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function variant(string $sku): ProductVariant
    {
        return Product::factory()->create()->variants()->create(['sku' => $sku, 'variant_name' => 'Standard', 'sell_price' => 1000, 'cost_price' => 500, 'current_stock' => 0]);
    }

    private function recipeWith(ProductVariant $ingredient, array $attrs = []): Recipe
    {
        $menu = $this->variant('MENU-'.++$this->seq);
        $recipe = Recipe::create($attrs + [
            'product_id' => $menu->product_id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id,
        ]);
        $recipe->variants()->sync([$menu->id]);
        $block = $recipe->blocks()->create(['variant_id' => $menu->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $block->lines()->create(['recipe_id' => $recipe->id, 'variant_id' => $ingredient->id, 'quantity' => 1, 'shrinkage_pct' => 0, 'display_order' => 1]);

        return $recipe;
    }

    public function test_contains_variants_referenced_by_non_archived_recipes_including_future_dated(): void
    {
        $active = $this->variant('ACTIVE');
        $future = $this->variant('FUTURE');
        $archived = $this->variant('ARCHIVED');
        $unused = $this->variant('UNUSED');

        $this->recipeWith($active);
        $this->recipeWith($future, ['effective_from' => now()->addDays(3)->toDateString()]);
        $this->recipeWith($archived, ['archived_at' => now()]);

        $set = app(IngredientVariants::class);
        $this->assertTrue($set->has($active->id));
        $this->assertTrue($set->has($future->id), 'resep berlaku-masa-depan tetap menjaga varian');
        $this->assertFalse($set->has($archived->id), 'resep arsip tidak menjaga');
        $this->assertFalse($set->has($unused->id));
    }

    public function test_material_lines_are_not_counted(): void
    {
        $menu = $this->variant('MENU-M');
        $recipe = Recipe::create(['product_id' => $menu->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->toDateString(), 'created_by' => $this->owner->id]);
        $block = $recipe->blocks()->create(['variant_id' => $menu->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => \App\Models\Material::factory()->create()->id, 'quantity' => 1, 'shrinkage_pct' => 0, 'display_order' => 1]);

        $this->assertSame([], app(IngredientVariants::class)->ids());
    }

    public function test_memo_is_forgotten_when_a_recipe_or_its_lines_change(): void
    {
        $ingredient = $this->variant('TLR');
        $set = app(IngredientVariants::class);
        $this->assertFalse($set->has($ingredient->id));

        $recipe = $this->recipeWith($ingredient); // menulis resep + baris tanpa RecipeService
        $this->assertTrue($set->has($ingredient->id), 'penulisan baris membuang memo');

        $recipe->forceFill(['archived_at' => now()])->save();
        $this->assertFalse($set->has($ingredient->id), 'pengarsipan membuang memo');

        $recipe->forceFill(['archived_at' => null])->save();
        $this->assertTrue($set->has($ingredient->id));
        $recipe->delete();
        $this->assertFalse($set->has($ingredient->id), 'penghapusan resep membuang memo');
    }

    public function test_it_loads_with_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 8) as $i) {
            $this->recipeWith($this->variant('V'.$i));
        }
        app(IngredientVariants::class)->forget();

        DB::enableQueryLog();
        $set = app(IngredientVariants::class);
        foreach (ProductVariant::pluck('id') as $id) {
            $set->has((int) $id);
        }
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'recipe'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $queries, 'dua kueri sekali per permintaan, bukan satu per varian');
    }
}
