<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VariantOutletStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US1 (FR-001..FR-005) — penanda "Gunakan sebagai bahan" di formulir produk: tersimpan, tercatat, dibatasi
 * (stok dilacak + bersumber varian + belum beresep), dan TIDAK mengubah kemampuan produk dijual langsung.
 */
class IngredientFlagTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private User $owner;

    private Product $telur;

    private ProductVariant $telurVariant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->telur = Product::factory()->create(['name' => 'Telur', 'home_outlet_id' => $this->outletA->id]);
        $this->telurVariant = $this->telur->variants()->create(['sku' => 'TLR-1', 'variant_name' => 'Standard', 'sell_price' => 3000, 'cost_price' => 1500, 'current_stock' => 0]);
    }

    private function updateTelur(array $extra = [], ?Product $product = null)
    {
        $product ??= $this->telur;

        return $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name] + $extra);
    }

    public function test_the_flag_defaults_to_off_and_is_saved_with_a_unit_label_and_logged(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->assertFalse($this->telur->fresh()->use_as_ingredient);

        $res = $this->updateTelur(['use_as_ingredient' => true, 'ingredient_unit' => 'pcs'])->assertOk();

        $res->assertJsonPath('use_as_ingredient', true)->assertJsonPath('ingredient_unit', 'pcs');
        $this->assertTrue($this->telur->fresh()->use_as_ingredient);
        $log = ActivityLog::where('action', 'ingredient_flag_changed')->sole();
        $this->assertFalse($log->old_values['use_as_ingredient']);
        $this->assertTrue($log->new_values['use_as_ingredient']);
    }

    public function test_an_unrelated_update_does_not_log_a_flag_change(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->updateTelur(['use_as_ingredient' => true])->assertOk();
        ActivityLog::where('action', 'ingredient_flag_changed')->delete();

        $this->updateTelur(['description' => 'baru', 'use_as_ingredient' => true])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'ingredient_flag_changed')->count());
    }

    public function test_a_new_product_can_be_created_already_flagged(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $res = $this->postJson('/api/v1/products', [
            'home_outlet_id' => $this->outletA->id, 'category_id' => $this->telur->category_id, 'name' => 'Beras',
            'use_as_ingredient' => true, 'ingredient_unit' => 'kg',
            'variants' => [['variant_name' => 'Standard', 'sell_price' => 15000]],
        ])->assertCreated();

        $res->assertJsonPath('use_as_ingredient', true)->assertJsonPath('ingredient_unit', 'kg');
    }

    public function test_it_is_refused_when_a_variant_does_not_track_stock(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->telurVariant->update(['track_stock' => false]);

        $this->updateTelur(['use_as_ingredient' => true])->assertStatus(422)->assertJsonValidationErrors('use_as_ingredient');
        $this->assertFalse($this->telur->fresh()->use_as_ingredient);
    }

    public function test_it_is_refused_for_a_product_whose_stock_comes_from_modifiers(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->telur->forceFill(['stock_source' => Product::STOCK_MODIFIER])->save();

        $this->updateTelur(['use_as_ingredient' => true])->assertStatus(422)->assertJsonValidationErrors('use_as_ingredient');
    }

    public function test_it_is_refused_when_a_variant_has_a_recipe_but_not_when_the_recipe_is_archived(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $recipe = Recipe::create([
            'product_id' => $this->telur->id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->owner->id,
        ]);
        $recipe->variants()->sync([$this->telurVariant->id]);

        $this->updateTelur(['use_as_ingredient' => true])->assertStatus(422)->assertJsonValidationErrors('use_as_ingredient');

        $recipe->forceFill(['archived_at' => now()])->save();
        $this->updateTelur(['use_as_ingredient' => true])->assertOk();
    }

    public function test_a_future_dated_recipe_also_blocks_the_flag(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $recipe = Recipe::create([
            'product_id' => $this->telur->id, 'name' => 'Nanti', 'yield' => 1,
            'effective_from' => now()->addDays(5)->toDateString(), 'created_by' => $this->owner->id,
        ]);
        $recipe->variants()->sync([$this->telurVariant->id]);

        $this->updateTelur(['use_as_ingredient' => true])->assertStatus(422)->assertJsonValidationErrors('use_as_ingredient');
    }

    public function test_turning_the_flag_off_is_always_allowed(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->updateTelur(['use_as_ingredient' => true])->assertOk();

        $this->updateTelur(['use_as_ingredient' => false])->assertOk()->assertJsonPath('use_as_ingredient', false);
    }

    public function test_a_role_without_the_products_menu_is_refused(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $this->updateTelur(['use_as_ingredient' => true])->assertForbidden();
        $this->assertFalse($this->telur->fresh()->use_as_ingredient);
    }

    public function test_a_flagged_product_can_still_be_sold_directly(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->updateTelur(['use_as_ingredient' => true])->assertOk();
        app(\App\Services\StockService::class)->applyMovement($this->telurVariant, $this->outletA->id, 'adjustment', 20);

        $this->actingAs($this->cashier, 'sanctum');
        $this->checkout([[$this->telurVariant, 3, []]])->assertCreated();

        $balance = (int) VariantOutletStock::where(['variant_id' => $this->telurVariant->id, 'outlet_id' => $this->outletA->id])->value('current_stock');
        $this->assertSame(17, $balance);
        $this->assertSame(1, StockMovement::where(['variant_id' => $this->telurVariant->id, 'type' => 'sale'])->count());
    }
}
