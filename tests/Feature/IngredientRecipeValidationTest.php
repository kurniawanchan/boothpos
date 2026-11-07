<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\User;
use App\Models\VariantOutletStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 093 US1 (FR-006..FR-009, FR-004) — baris resep boleh merujuk varian produk bahan: tepat satu dari bahan baku/varian,
 * bilangan bulat per porsi tanpa susut, tanpa saldo atau catatan bahan baku baru, biaya dari harga pokok varian.
 * Larangan rekursi/diri-sendiri ada di IngredientRecursionTest (US6).
 */
class IngredientRecipeValidationTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $menu;

    private ProductVariant $telur;

    private ProductVariant $mie;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->menu = $this->variant('Indomie Kuah', false, 'MENU-1');
        $this->telur = $this->variant('Telur', true, 'TLR-1', 1500);
        $this->mie = $this->variant('Indomie Goreng', true, 'MIE-1', 2800);
    }

    private function variant(string $name, bool $ingredient, string $sku, float $cost = 0): ProductVariant
    {
        $product = Product::factory()->create(['name' => $name, 'use_as_ingredient' => $ingredient, 'ingredient_unit' => $ingredient ? 'pcs' : null]);

        return $product->variants()->create(['sku' => $sku, 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => $cost, 'current_stock' => 0]);
    }

    private function saveRecipe(array $lines, array $over = [])
    {
        return $this->postJson("/api/v1/products/{$this->menu->product_id}/recipes", array_merge([
            'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->toDateString(),
            'variant_ids' => [$this->menu->id], 'lines' => $lines,
        ], $over));
    }

    public function test_a_recipe_saves_product_lines_without_creating_materials_or_balances(): void
    {
        $materials = Material::count();
        $balances = VariantOutletStock::count();

        $res = $this->saveRecipe([
            ['variant_id' => $this->mie->id, 'quantity' => 1, 'shrinkage_pct' => 0],
            ['variant_id' => $this->telur->id, 'quantity' => 1],
        ])->assertCreated();

        $this->assertSame($materials, Material::count(), 'tanpa catatan bahan baku baru');
        $this->assertSame($balances, VariantOutletStock::count(), 'tanpa saldo stok baru');
        $this->assertSame(2, RecipeLine::whereNotNull('variant_id')->count());
        $line = $res->json('lines.1');
        $this->assertNull($line['material_id']);
        $this->assertSame($this->telur->id, $line['variant_id']);
        $this->assertSame('Telur', $line['name']);
        $this->assertSame('TLR-1', $line['sku']);
        $this->assertSame('pcs', $line['unit']);
        $this->assertSame('product', $line['kind']);
        $this->assertSame('0.00', $line['shrinkage_pct']);
    }

    public function test_the_blocks_shape_works_and_material_and_product_lines_mix_in_one_recipe(): void
    {
        $kuah = Material::factory()->create(['name' => 'Kuah kaldu', 'unit' => 'ml']);

        $this->postJson("/api/v1/products/{$this->menu->product_id}/recipes", [
            'name' => 'Campur', 'yield' => 1, 'effective_from' => now()->toDateString(),
            'blocks' => [['variant_id' => $this->menu->id, 'modifier_option_id' => null, 'lines' => [
                ['variant_id' => $this->telur->id, 'quantity' => 1],
                ['material_id' => $kuah->id, 'quantity' => 200],
            ]]],
        ])->assertCreated()->assertJsonPath('blocks.0.lines.0.kind', 'product')->assertJsonPath('blocks.0.lines.1.material_id', $kuah->id);

        $this->assertSame(1, RecipeLine::whereNotNull('variant_id')->count());
        $this->assertSame(1, RecipeLine::whereNotNull('material_id')->count());
    }

    public function test_exactly_one_of_material_or_variant_is_required(): void
    {
        $kuah = Material::factory()->create();

        $this->saveRecipe([['material_id' => $kuah->id, 'variant_id' => $this->telur->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.material_id');
        $this->saveRecipe([['quantity' => 1]])->assertStatus(422);
        $this->assertSame(0, Recipe::count());
    }

    public function test_a_product_line_cannot_carry_shrinkage(): void
    {
        $this->saveRecipe([['variant_id' => $this->telur->id, 'quantity' => 1, 'shrinkage_pct' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.shrinkage_pct');
    }

    public function test_the_quantity_per_portion_must_be_a_whole_number_of_at_least_one(): void
    {
        // 1 telur untuk 2 porsi = 0,5 per porsi: stok varian bilangan bulat, jadi ditolak (bukan dibulatkan diam-diam).
        $this->saveRecipe([['variant_id' => $this->telur->id, 'quantity' => 1]], ['yield' => 2])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
        $this->saveRecipe([['variant_id' => $this->telur->id, 'quantity' => 1.5]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');

        // 4 telur untuk 2 porsi = 2 per porsi: lolos.
        $this->saveRecipe([['variant_id' => $this->telur->id, 'quantity' => 4]], ['yield' => 2])->assertCreated();
    }

    public function test_the_same_product_variant_cannot_appear_twice_in_one_block(): void
    {
        $this->saveRecipe([
            ['variant_id' => $this->telur->id, 'quantity' => 1],
            ['variant_id' => $this->telur->id, 'quantity' => 2],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.1.variant_id');
    }

    public function test_an_unknown_or_deleted_variant_is_refused(): void
    {
        $this->saveRecipe([['variant_id' => 999999, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('lines.0.variant_id');

        $this->telur->delete();
        $this->saveRecipe([['variant_id' => $this->telur->id, 'quantity' => 1]])->assertStatus(422)->assertJsonValidationErrors('lines.0.variant_id');
    }

    public function test_a_product_that_is_not_flagged_is_refused_for_a_new_line(): void
    {
        $plain = $this->variant('Gula', false, 'GLA-1');

        $this->saveRecipe([['variant_id' => $plain->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.variant_id');
    }

    public function test_existing_lines_survive_turning_the_flag_off_but_new_unflagged_lines_are_refused(): void
    {
        $id = $this->saveRecipe([['variant_id' => $this->telur->id, 'quantity' => 1]])->assertCreated()->json('id');
        $this->telur->product->update(['use_as_ingredient' => false]);

        // Menyimpan ulang resep yang sama (nama berubah) tetap lolos: FR-004, resep lama utuh.
        $this->putJson("/api/v1/recipes/{$id}", [
            'name' => 'Dasar v2', 'yield' => 1, 'effective_from' => now()->toDateString(),
            'variant_ids' => [$this->menu->id], 'lines' => [['variant_id' => $this->telur->id, 'quantity' => 1]],
        ])->assertOk();
        $this->assertSame(1, RecipeLine::where('variant_id', $this->telur->id)->count());

        // Menambah baris BARU dari produk yang tak bertanda ditolak.
        $this->putJson("/api/v1/recipes/{$id}", [
            'name' => 'Dasar v2', 'yield' => 1, 'effective_from' => now()->toDateString(), 'variant_ids' => [$this->menu->id],
            'lines' => [['variant_id' => $this->telur->id, 'quantity' => 1], ['variant_id' => $this->mie->id, 'quantity' => 1]],
        ])->assertOk(); // mie masih bertanda
        $this->mie->product->update(['use_as_ingredient' => false]);
        $this->postJson("/api/v1/products/{$this->menu->product_id}/recipes", [
            'name' => 'Lain', 'yield' => 1, 'effective_from' => now()->toDateString(), 'variant_ids' => [$this->menu->id],
            'lines' => [['variant_id' => $this->mie->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_product_line_costs_come_from_the_variant_cost_price_and_zero_means_unknown(): void
    {
        // 2 × 1.500 + 1 × 2.800 = 5.800 per porsi.
        $this->saveRecipe([
            ['variant_id' => $this->telur->id, 'quantity' => 2],
            ['variant_id' => $this->mie->id, 'quantity' => 1],
        ])->assertCreated()
            ->assertJsonPath('lines.0.unit_cost', '1500.0000')
            ->assertJsonPath('lines.0.line_cost', '3000.0000')
            ->assertJsonPath('portion_cost', '5800.00')
            ->assertJsonPath('incomplete', false);

        $free = $this->variant('Air', true, 'AIR-1', 0);
        $this->saveRecipe([['variant_id' => $free->id, 'quantity' => 1]], ['name' => 'Air'])->assertCreated()
            ->assertJsonPath('lines.0.unit_cost', null)->assertJsonPath('incomplete', true);
    }
}
