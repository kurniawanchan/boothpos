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
 * 093 US4 (FR-017, FR-020) — penjualan LANGSUNG produk bahan tidak boleh menembus bahan yang sudah dijanjikan tab terbuka
 * (tahanan resep). Kekurangan murni (tanpa tahanan) tetap berperilaku lama.
 */
class IngredientHoldTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 50]);
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
    }

    private function tabWith(int $menuQty): int
    {
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $id = $tab['id'] ?? $tab['data']['id'];
        $this->apiAddLine($id, $this->nasiGoreng->id, $menuQty)->assertSuccessful();

        return $id;
    }

    private function assertReserved($response): void
    {
        $response->assertStatus(409)->assertJsonPath('code', 'insufficient_stock_reserved')
            ->assertJsonPath('variant_id', $this->ing['telur']->id)->assertJsonPath('sku', $this->ing['telur']->sku);
    }

    public function test_a_direct_sale_cannot_eat_into_what_an_open_tab_has_reserved(): void
    {
        $this->tabWith(40);   // menahan 40 dari 50

        $r = $this->checkout([[$this->ing['telur'], 11, []]]);

        $this->assertReserved($r);
        $this->assertSame(10, $r->json('available'));
        $this->assertSame(40, $r->json('reserved'));
        $this->assertSame(50, $this->vBalance('telur'), 'tidak ada yang terpotong');
        $this->assertSame(1, Order::count(), 'hanya tab; checkout tidak meninggalkan pesanan');

        $this->checkout([[$this->ing['telur'], 10, []]])->assertCreated();   // tepat sisanya
        $this->assertSame(40, $this->vBalance('telur'));
    }

    public function test_the_same_tab_reserves_its_own_recipe_need_against_its_own_direct_lines(): void
    {
        $tabId = $this->tabWith(5);   // menahan 5

        $this->assertReserved($this->apiAddLine($tabId, $this->ing['telur']->id, 46));   // 50 − 46 = 4 < 5
        $this->apiAddLine($tabId, $this->ing['telur']->id, 45)->assertSuccessful();      // 50 − 45 = 5 ≥ 5
    }

    public function test_a_plain_shortage_without_holds_keeps_the_old_behaviour(): void
    {
        app(\App\Services\StockService::class)->applyMovement($this->ing['telur'], $this->outletA->id, 'adjustment', -45);   // sisa 5

        $r = $this->checkout([[$this->ing['telur'], 6, []]]);

        // Bentuk lama: kekurangan stok murni sudah 409 dengan galat `qty_change` (tanpa `code`); tidak berubah oleh 093.
        $r->assertStatus(409)->assertJsonValidationErrors('qty_change');
        $this->assertNull($r->json('code'));
        $this->assertSame(5, $this->vBalance('telur'));
    }

    public function test_the_hold_still_applies_after_the_flag_is_turned_off_while_a_recipe_references_it(): void
    {
        $this->tabWith(40);
        $this->ing['telur']->product->update(['use_as_ingredient' => false]);

        $this->assertReserved($this->checkout([[$this->ing['telur'], 11, []]]));
    }

    public function test_completing_the_holding_tab_is_not_blocked_by_its_own_hold(): void
    {
        $tabId = $this->tabWith(40);

        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 5000000]], (string) \Illuminate\Support\Str::uuid())->assertSuccessful();

        $this->assertSame(10, $this->vBalance('telur'));
    }

    public function test_a_product_that_no_recipe_uses_never_touches_the_hold_computation(): void
    {
        $plain = $this->makeIngredient('Gula', 30, ['use_as_ingredient' => false]);
        // Subkelas penghitung sungguhan (bukan mock: mock tanpa konstruktor merusak dependensinya).
        $spy = new class(app(\App\Services\RecipeService::class), app(\App\Services\ModifierMaterialConsumption::class)) extends MenuStockService {
            public int $heldCalls = 0;

            public function heldVariantsBy(int $outletId, ?int $exceptOrderId): array
            {
                $this->heldCalls++;

                return parent::heldVariantsBy($outletId, $exceptOrderId);
            }
        };
        $this->app->instance(MenuStockService::class, $spy);

        $this->checkout([[$plain, 3, []]])->assertCreated();

        $this->assertSame(27, $this->vBalance($plain));
        $this->assertSame(0, $spy->heldCalls, 'produk di luar resep tidak menghitung tahanan sama sekali');
    }

    public function test_returns_and_adjustments_are_never_blocked_by_holds(): void
    {
        $this->tabWith(50);   // menahan seluruh saldo

        app(\App\Services\StockService::class)->applyMovement($this->ing['telur'], $this->outletA->id, 'adjustment', 5);
        app(\App\Services\StockService::class)->applyMovement($this->ing['telur'], $this->outletA->id, 'adjustment', -5);

        $this->assertSame(50, $this->vBalance('telur'));
    }
}
