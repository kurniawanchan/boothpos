<?php

namespace Tests\Feature;

use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 2026-11-02 — migrasi resep → blok: satu resep dengan N varian dulu berbagi satu daftar bahan; kini tiap varian punya blok
 * dasarnya sendiri dengan salinan bahan (varian pertama memakai baris asli). Bentuk lama dinyalakan lewat down(), data lama
 * ditanam, lalu up() dijalankan lagi. DDL MySQL meng-commit transaksi tes, jadi baris dibersihkan sendiri di `finally`.
 */
class RecipeBlocksMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    public function test_each_variant_gets_its_own_base_block_with_a_copy_of_the_shared_lines(): void
    {
        $a = $this->makeVariant();
        $b = $a->product->variants()->create(['sku' => 'RSTBLK0001', 'variant_name' => 'Large', 'sell_price' => 30000, 'cost_price' => 10000, 'current_stock' => 0]);
        $m1 = Material::create(['name' => 'Cabai', 'unit' => 'gram', 'kind' => 'ingredient', 'code' => 'BLK-1']);
        $m2 = Material::create(['name' => 'Garam', 'unit' => 'gram', 'kind' => 'ingredient', 'code' => 'BLK-2']);

        $migration = require database_path('migrations/2027_01_02_000001_create_recipe_blocks.php');
        $migration->down(); // kembali ke bentuk lama (tanpa blok)

        $recipeId = null;
        try {
            $recipeId = DB::table('recipes')->insertGetId([
                'product_id' => $a->product_id, 'yield' => 1, 'effective_from' => now()->toDateString(), 'name' => 'Dasar',
                'data_mode' => 'live', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('recipe_variants')->insert([['recipe_id' => $recipeId, 'variant_id' => $a->id], ['recipe_id' => $recipeId, 'variant_id' => $b->id]]);
            DB::table('recipe_lines')->insert([
                ['recipe_id' => $recipeId, 'material_id' => $m1->id, 'quantity' => 2, 'shrinkage_pct' => 0, 'display_order' => 1],
                ['recipe_id' => $recipeId, 'material_id' => $m2->id, 'quantity' => 3, 'shrinkage_pct' => 10, 'display_order' => 2],
            ]);

            $migration->up();

            $blocks = DB::table('recipe_blocks')->where('recipe_id', $recipeId)->orderBy('id')->get();
            $this->assertCount(2, $blocks);
            $this->assertEqualsCanonicalizing([$a->id, $b->id], $blocks->pluck('variant_id')->all());
            $this->assertNull($blocks[0]->modifier_option_id);

            foreach ($blocks as $block) {
                $lines = DB::table('recipe_lines')->where('block_id', $block->id)->orderBy('display_order')->get();
                $this->assertSame([$m1->id, $m2->id], $lines->pluck('material_id')->all());
                $this->assertEquals([2.0, 3.0], $lines->map(fn ($l) => (float) $l->quantity)->all());
                $this->assertEquals([0.0, 10.0], $lines->map(fn ($l) => (float) $l->shrinkage_pct)->all());
            }
            $this->assertSame(4, DB::table('recipe_lines')->where('recipe_id', $recipeId)->count(), 'bahan disalin per varian, tak ada yang hilang');
            $this->assertSame(0, DB::table('recipe_lines')->where('recipe_id', $recipeId)->whereNull('block_id')->count());
        } finally {
            if (! Schema::hasTable('recipe_blocks')) {
                $migration->up();
            }
            if ($recipeId !== null) {
                DB::table('recipes')->where('id', $recipeId)->delete();
            }
        }
    }
}
