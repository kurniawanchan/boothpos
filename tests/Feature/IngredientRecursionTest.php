<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 093 US6 (FR-010, SC-010) — resep rekursif DILARANG dari dua arah: menu beresep tidak boleh menjadi bahan, produk bahan
 * tidak boleh diberi resep, dan produk tidak boleh menjadi bahan resepnya sendiri. Resep berlaku-masa-depan menjaga,
 * resep arsip tidak.
 */
class IngredientRecursionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function variant(string $name, bool $ingredient = false): ProductVariant
    {
        $p = Product::factory()->create(['name' => $name, 'use_as_ingredient' => $ingredient]);

        return $p->variants()->create(['sku' => 'RC'.++$this->n, 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 1000, 'current_stock' => 0]);
    }

    private function saveRecipe(ProductVariant $menu, ProductVariant $ingredient, array $over = [])
    {
        return $this->postJson("/api/v1/products/{$menu->product_id}/recipes", array_merge([
            'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->toDateString(),
            'variant_ids' => [$menu->id], 'lines' => [['variant_id' => $ingredient->id, 'quantity' => 1]],
        ], $over));
    }

    private function recipeOn(ProductVariant $menu, array $attrs = []): Recipe
    {
        $r = Recipe::create($attrs + ['product_id' => $menu->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id]);
        $r->variants()->sync([$menu->id]);

        return $r;
    }

    public function test_a_menu_with_a_recipe_cannot_be_used_as_an_ingredient(): void
    {
        $kuah = $this->variant('Indomie Kuah');
        $this->recipeOn($kuah);
        $paket = $this->variant('Paket Hemat');

        $this->saveRecipe($paket, $kuah)->assertStatus(422)->assertJsonValidationErrors('lines.0.variant_id');
        $this->assertSame(1, Recipe::count());
    }

    public function test_a_menu_with_only_a_future_dated_recipe_is_also_refused_but_an_archived_one_is_not(): void
    {
        $future = $this->variant('Menu Nanti');
        $this->recipeOn($future, ['effective_from' => now()->addDays(4)->toDateString()]);
        $archived = $this->variant('Menu Lama');
        $this->recipeOn($archived, ['archived_at' => now()]);
        $host = $this->variant('Paket');
        $archived->product->update(['use_as_ingredient' => true]);
        $future->product->update(['use_as_ingredient' => true]);

        $this->saveRecipe($host, $future)->assertStatus(422)->assertJsonValidationErrors('lines.0.variant_id');
        $this->saveRecipe($host, $archived)->assertCreated();
    }

    public function test_a_product_cannot_be_an_ingredient_of_its_own_recipe(): void
    {
        $self = $this->variant('Telur', true);

        $this->saveRecipe($self, $self)->assertStatus(422);
    }

    public function test_a_second_variant_of_the_same_product_is_also_refused_as_self(): void
    {
        $a = $this->variant('Kopi', false);
        $b = $a->product->variants()->create(['sku' => 'RC9'.++$this->n, 'variant_name' => 'Besar', 'sell_price' => 12000, 'cost_price' => 1000, 'current_stock' => 0]);
        $a->product->update(['use_as_ingredient' => true]);

        $this->saveRecipe($a, $b)->assertStatus(422);
        $this->assertSame(0, Recipe::count());
    }

    public function test_a_flagged_product_cannot_be_given_a_recipe(): void
    {
        $telur = $this->variant('Telur', true);
        $mie = $this->variant('Indomie Goreng', true);

        $this->saveRecipe($telur, $mie)->assertStatus(422);
        $this->assertSame(0, Recipe::count());
    }

    public function test_a_menu_that_already_has_a_recipe_cannot_be_flagged_as_an_ingredient(): void
    {
        $kuah = $this->variant('Indomie Kuah');
        $this->recipeOn($kuah);

        $this->putJson("/api/v1/products/{$kuah->product_id}", ['name' => $kuah->product->name, 'use_as_ingredient' => true])
            ->assertStatus(422)->assertJsonValidationErrors('use_as_ingredient');
        $this->assertFalse($kuah->product->fresh()->use_as_ingredient);
    }

    public function test_a_two_step_cycle_cannot_be_built_in_either_order(): void
    {
        $a = $this->variant('A', true);
        $b = $this->variant('B');

        // B beresep memakai A (sah: A adalah bahan).
        $this->saveRecipe($b, $a)->assertCreated();
        // A tidak bisa diberi resep yang memakai B (A bertanda bahan) — dan B tidak bisa dijadikan bahan (B beresep).
        $this->saveRecipe($a, $b)->assertStatus(422);
        $b->product->update(['use_as_ingredient' => false]);
        $this->putJson("/api/v1/products/{$b->product_id}", ['name' => 'B', 'use_as_ingredient' => true])->assertStatus(422);
        $this->assertSame(1, Recipe::count());
    }
}
