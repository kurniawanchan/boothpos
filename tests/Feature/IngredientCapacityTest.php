<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\MenuStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US3 (FR-018..FR-020) — stok menu beresep = kapasitas TERENDAH di antara bahan baku DAN produk bahan, memakai saldo
 * outlet dikurangi tahanan tab terbuka; penjaga menolak 409 `insufficient_recipe_stock` untuk bahan produk juga.
 */
class IngredientCapacityTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 50]);
    }

    private function stock(?\App\Models\ProductVariant $menu = null): array
    {
        $menu ??= $this->nasiGoreng;

        return app(MenuStockService::class)->forVariants([$menu->id], $this->outletA->id)[$menu->id] ?? [];
    }

    private function openTabWith(int $qty): int
    {
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $tabId = $tab['id'] ?? $tab['data']['id'];
        $this->apiAddLine($tabId, $this->nasiGoreng->id, $qty)->assertSuccessful();

        return $tabId;
    }

    public function test_the_scarcest_product_ingredient_decides_the_menu_stock(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);

        $s = $this->stock();

        $this->assertSame(50, $s['portions'], 'telur 50 membatasi walau mie 100');
        $this->assertSame('variant', $s['limiting']['ingredient_type']);
        $this->assertSame($this->ing['telur']->id, $s['limiting']['variant_id']);
        $this->assertNull($s['limiting']['material_id']);
        $this->assertSame('Telur', $s['limiting']['name']);
        $this->assertSame('pcs', $s['limiting']['unit']);
        $this->assertSame('50.000', $s['limiting']['on_hand']);
        $this->assertSame('1.000', $s['limiting']['per_portion']);
    }

    public function test_a_raw_material_can_be_the_limit_and_wins_a_tie(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1, 'm:soto' => 10]);   // soto 50 ÷ 10 = 5 < telur 50
        $s = $this->stock();
        $this->assertSame(5, $s['portions']);
        $this->assertSame('material', $s['limiting']['ingredient_type']);
        $this->assertSame($this->mat['soto']->id, $s['limiting']['material_id']);
        $this->assertNull($s['limiting']['variant_id'] ?? null);
    }

    public function test_a_tie_between_a_material_and_a_product_falls_to_the_material(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1, 'm:soto' => 1]);   // soto 50 ÷ 1 = 50 = telur 50

        $this->assertSame('material', $this->stock()['limiting']['ingredient_type'], 'seri deterministik: bahan baku dulu');
    }

    public function test_quantity_and_yield_per_portion_apply_to_product_ingredients(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 4], yield: 2);   // 2 per porsi

        $this->assertSame(25, $this->stock()['portions']);
    }

    public function test_direct_sales_of_the_same_product_lower_the_menu_stock(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        $this->checkout([[$this->ing['telur'], 10, []]])->assertCreated();

        $this->assertSame(40, $this->stock()['portions']);
    }

    public function test_open_tabs_hold_product_ingredients_for_everyone(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);
        $this->openTabWith(5);

        $this->assertSame(45, $this->stock()['portions'], '50 − 5 ditahan tab terbuka');
        $this->assertSame(50, $this->vBalance('telur'), 'belum dipotong sampai selesai');
        $this->assertEquals(['telur' => 5, 'mie' => 5], collect(app(MenuStockService::class)->heldVariantsBy($this->outletA->id, null))
            ->mapWithKeys(fn ($q, $id) => [$id === $this->ing['telur']->id ? 'telur' : 'mie' => $q])->all());
    }

    public function test_an_empty_or_negative_ingredient_means_zero_portions(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        app(\App\Services\StockService::class)->applyMovement($this->ing['telur'], $this->outletA->id, 'adjustment', -50);

        $this->assertSame(0, $this->stock()['portions']);
    }

    public function test_a_menu_without_product_lines_is_unchanged(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['m:soto' => 1]);

        $s = $this->stock();
        $this->assertSame(50, $s['portions']);
        $this->assertSame('material', $s['limiting']['ingredient_type']);
    }

    // --- penjaga (409) --------------------------------------------------------------------------------------------------

    private function assertProductRefusal($response, string $key = 'telur'): void
    {
        $response->assertStatus(409)->assertJsonPath('code', 'insufficient_recipe_stock')
            ->assertJsonPath('limiting.variant_id', $this->ing[$key]->id)->assertJsonPath('limiting.ingredient_type', 'variant');
    }

    public function test_checkout_over_the_product_stock_is_refused_409_and_leaves_nothing_behind(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);

        $this->assertProductRefusal($this->checkout([[$this->nasiGoreng, 51, []]], null, 5000000));

        $this->assertSame(0, Order::count());
        $this->assertSame(50, $this->vBalance('telur'));
        $this->assertSame(100, $this->vBalance('mie'));
        $this->assertCount(0, $this->recipeMoves('telur'));
    }

    public function test_a_tab_cannot_add_more_than_what_other_open_tabs_leave(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        $this->openTabWith(40);

        $second = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $secondId = $second['id'] ?? $second['data']['id'];
        $this->assertProductRefusal($this->apiAddLine($secondId, $this->nasiGoreng->id, 11));
        $this->apiAddLine($secondId, $this->nasiGoreng->id, 10)->assertSuccessful();   // tepat sisanya
    }

    public function test_a_refusal_names_what_is_left_for_the_menu(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);

        $r = $this->checkout([[$this->nasiGoreng, 60, []]], null, 5000000)->assertStatus(409);

        $this->assertSame(50, $r->json('limiting.available_portions'));
        $this->assertSame('Telur', $r->json('limiting.name'));
    }
}
