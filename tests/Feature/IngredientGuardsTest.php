<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReasonCode;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US6 (FR-004, FR-011; research D8) — perubahan yang diam-diam menghentikan pemakaian resep ditolak 409
 * `ingredient_in_use` (menyebut resepnya); mematikan penanda selalu boleh dan resep lama tetap memotong stok.
 */
class IngredientGuardsTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 100]);
        $this->recipe = $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1], name: 'Nasi Telur');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function product(): Product
    {
        return $this->ing['telur']->product;
    }

    private function assertInUse($response): void
    {
        $response->assertStatus(409)->assertJsonPath('code', 'ingredient_in_use')
            ->assertJsonPath('recipes.0.id', $this->recipe->id)->assertJsonPath('recipes.0.name', 'Nasi Telur')
            ->assertJsonPath('recipes.0.product_name', $this->nasiGoreng->product->name);
    }

    public function test_turning_the_flag_off_is_allowed_and_the_old_recipe_keeps_deducting(): void
    {
        $this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur', 'use_as_ingredient' => false])->assertOk();

        $this->actingAs($this->cashier, 'sanctum');
        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();

        $this->assertSame(98, $this->vBalance('telur'), 'FR-004: resep lama tetap memotong walau penanda mati');
    }

    public function test_turning_the_flag_back_on_makes_the_product_pickable_again(): void
    {
        $this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur', 'use_as_ingredient' => false])->assertOk();
        $this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur', 'use_as_ingredient' => true])->assertOk();

        $names = array_column($this->getJson('/api/v1/recipe-ingredients?exclude_product_id='.$this->nasiGoreng->product_id, ['X-Outlet-Id' => (string) $this->outletA->id])->assertOk()->json('data'), 'product_name');
        $this->assertContains('Telur', $names);
    }

    public function test_switching_the_stock_source_to_modifier_is_refused_while_used(): void
    {
        $this->assertInUse($this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur', 'stock_source' => 'modifier']));
        $this->assertSame('variant', $this->product()->fresh()->stock_source);
    }

    public function test_deactivating_the_product_is_refused_while_used(): void
    {
        $this->assertInUse($this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur', 'is_active' => false]));
        $this->assertTrue((bool) $this->product()->fresh()->is_active);
    }

    public function test_turning_off_stock_tracking_or_deactivating_the_variant_is_refused_while_used(): void
    {
        $v = $this->ing['telur'];

        $this->assertInUse($this->putJson("/api/v1/variants/{$v->id}", ['track_stock' => false]));
        $this->assertInUse($this->putJson("/api/v1/variants/{$v->id}", ['is_active' => false]));
        $this->assertTrue((bool) $v->fresh()->track_stock);
        $this->assertTrue((bool) $v->fresh()->is_active);
    }

    public function test_deactivating_through_the_variant_batch_is_refused_while_used(): void
    {
        $v = $this->ing['telur'];

        $this->assertInUse($this->putJson("/api/v1/products/{$v->product_id}/variants", ['variants' => [['id' => $v->id, 'variant_name' => 'Standard', 'sell_price' => 3000, 'is_active' => false]]]));
        $this->assertTrue((bool) $v->fresh()->is_active);
    }

    public function test_discontinuing_the_product_is_refused_while_used(): void
    {
        $reason = (int) ReasonCode::where('category', 'discontinue')->orderBy('sort')->value('id');

        $this->assertInUse($this->postJson("/api/v1/products/{$this->product()->id}/discontinue", ['reason_code_id' => $reason]));
    }

    public function test_permanently_deleting_the_variant_is_a_409_with_the_recipe_not_a_database_error(): void
    {
        $this->assertInUse($this->deleteJson("/api/v1/variants/{$this->ing['telur']->id}/permanent"));
        $this->assertNotNull(ProductVariant::find($this->ing['telur']->id));
    }

    public function test_an_archived_recipe_does_not_block_anything(): void
    {
        $this->recipe->forceFill(['archived_at' => now()])->save();

        $this->putJson("/api/v1/variants/{$this->ing['telur']->id}", ['track_stock' => false])->assertOk();
        $this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur', 'is_active' => false])->assertOk();
    }

    public function test_removing_the_line_or_the_recipe_frees_the_product(): void
    {
        $this->deleteJson("/api/v1/recipes/{$this->recipe->id}")->assertSuccessful();

        $this->putJson("/api/v1/variants/{$this->ing['telur']->id}", ['is_active' => false])->assertOk();
    }

    public function test_unrelated_edits_on_a_used_product_are_never_blocked(): void
    {
        $v = $this->ing['telur'];

        $this->putJson("/api/v1/products/{$this->product()->id}", ['name' => 'Telur Ayam', 'description' => 'segar'])->assertOk();
        $this->putJson("/api/v1/variants/{$v->id}", ['variant_name' => 'Standard', 'low_stock_alert' => 10])->assertOk();
        $this->putJson("/api/v1/variants/{$v->id}", ['track_stock' => true, 'is_active' => true])->assertOk();   // nilai sama bukan perubahan
    }

    public function test_a_product_no_recipe_uses_is_not_guarded_at_all(): void
    {
        $free = $this->makeIngredient('Gula', 10, ['use_as_ingredient' => false]);

        $this->putJson("/api/v1/variants/{$free->id}", ['track_stock' => false])->assertOk();
        $this->putJson("/api/v1/products/{$free->product_id}", ['name' => 'Gula', 'is_active' => false])->assertOk();
    }
}
