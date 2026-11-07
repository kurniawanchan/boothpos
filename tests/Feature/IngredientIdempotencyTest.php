<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\ModifierMaterialConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US2 (FR-016, SC-005) — kiriman ulang tidak memotong lagi. Termasuk pesanan yang HANYA berbahan produk: sabuk
 * pengaman buku besar lama hanya melihat buku besar bahan baku, jadi harus diperluas ke `stock_movements`.
 */
class IngredientIdempotencyTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld();
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);
    }

    public function test_retrying_the_same_checkout_deducts_product_ingredients_once(): void
    {
        $ref = (string) Str::uuid();

        $this->checkout([[$this->nasiGoreng, 2, []]], $ref)->assertCreated();
        foreach (range(1, 4) as $_) {
            $this->checkout([[$this->nasiGoreng, 2, []]], $ref);
        }

        $this->assertSame(198, $this->vBalance('telur'), 'lima kiriman, satu pemotongan');
        $this->assertSame(98, $this->vBalance('mie'));
        $this->assertCount(1, $this->recipeMoves('telur'));
        $this->assertSame(1, Order::count());
    }

    public function test_calling_consume_twice_on_an_order_with_only_product_ingredients_deducts_once(): void
    {
        $id = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated()->json('id');
        $order = Order::findOrFail($id);

        app(ModifierMaterialConsumption::class)->consumeOrder($order, $this->cashier);
        app(ModifierMaterialConsumption::class)->consumeOrder($order, $this->cashier);

        $this->assertSame(199, $this->vBalance('telur'));
        $this->assertCount(1, $this->recipeMoves('telur'));
    }
}
