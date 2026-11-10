<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US3 (FR-020, FR-021; contracts §4) — payload POS: menu beresep membawa stok dari bahan produk; varian produk bahan
 * membawa `outlet_stock` = saldo − tahanan; bentuk lama tidak berubah; jumlah kueri tetap.
 */
class IngredientPayloadTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 50]);
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);
    }

    private function posVariant(int $variantId): array
    {
        foreach ($this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk()->json('data') as $p) {
            foreach ($p['variants'] as $v) {
                if ($v['id'] === $variantId) {
                    return $v;
                }
            }
        }
        $this->fail("varian {$variantId} tidak ada di payload POS");
    }

    private function holdFive(): void
    {
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $this->apiAddLine($tab['id'] ?? $tab['data']['id'], $this->nasiGoreng->id, 5)->assertSuccessful();
    }

    public function test_the_menu_variant_carries_stock_from_product_ingredients_in_the_fields_clients_already_read(): void
    {
        $v = $this->posVariant($this->nasiGoreng->id);

        $this->assertSame(50, $v['outlet_stock']);
        $this->assertTrue($v['track_stock']);
        $this->assertSame('recipe', $v['stock_basis']);
        $this->assertSame('variant', $v['stock_limiting']['ingredient_type']);
        $this->assertSame($this->ing['telur']->id, $v['stock_limiting']['variant_id']);
    }

    public function test_an_ingredient_product_shows_its_balance_minus_holds(): void
    {
        $this->assertSame(50, $this->posVariant($this->ing['telur']->id)['outlet_stock']);

        $this->holdFive();

        $telur = $this->posVariant($this->ing['telur']->id);
        $this->assertSame(45, $telur['outlet_stock'], 'saldo 50 − 5 ditahan tab terbuka');
        $this->assertSame('variant', $telur['stock_basis'], 'bentuk lama tidak berubah');
        $this->assertSame(95, $this->posVariant($this->ing['mie']->id)['outlet_stock']);
        $this->assertSame(45, $this->posVariant($this->nasiGoreng->id)['outlet_stock']);
    }

    public function test_the_lookup_endpoint_gives_the_same_numbers(): void
    {
        $this->holdFive();

        $rows = $this->getJson('/api/v1/variants/lookup?pos=1&q=Telur')->assertOk()->json('data');
        $telur = collect($rows)->firstWhere('variant_id', $this->ing['telur']->id);

        $this->assertSame(45, $telur['outlet_stock']);
    }

    public function test_a_non_ingredient_product_is_untouched(): void
    {
        $plain = $this->makeIngredient('Gula', 30, ['use_as_ingredient' => false]);
        $this->holdFive();

        $this->assertSame(30, $this->posVariant($plain->id)['outlet_stock']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_ingredient_variants(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->holdFive();
        $count();
        $few = $count();

        foreach (range(1, 10) as $i) {
            $this->makeIngredient('Bahan '.$i, $i);
        }
        $many = $count();

        $this->assertLessThanOrEqual($few + 4, $many, "kueri tumbuh dari {$few} ke {$many}: harus tetap terhadap banyaknya varian (Konstitusi V)");
    }
}
