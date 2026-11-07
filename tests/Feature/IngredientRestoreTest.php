<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\User;
use App\Services\ModifierMaterialConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US2 (FR-024, FR-025) — void/refund memulihkan `recipe_sale − recipe_return` per (varian, baris) dari BUKU BESAR,
 * bukan dari resep hari ini; batal "buang" menulis `recipe_waste` dan tidak pernah kembali.
 */
class IngredientRestoreTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld();
    }

    private function sale(int $qty = 2): int
    {
        return $this->checkout([[$this->nasiGoreng, $qty, []]])->assertCreated()->json('id');
    }

    private function refund(int $orderId)
    {
        $subject = ['order_id' => $orderId, 'reason_code' => 'wrong_item', 'reason' => 'salah pesan'];
        $token = $this->overrideToken('refund', $subject, $this->outletA->id);

        return $this->postJson("/api/v1/orders/{$orderId}/refund", $subject + ['override_token' => $token]);
    }

    private function voidAsOwner(int $orderId)
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        return $this->postJson("/api/v1/orders/{$orderId}/void", ['reason' => 'salah input']);
    }

    public function test_a_void_returns_exactly_what_was_deducted_even_after_the_recipe_changed(): void
    {
        $recipe = $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 2]);
        $id = $this->sale(2); // mie −2, telur −4
        $recipe->lines()->update(['quantity' => 9]);   // resep diubah SESUDAH jual
        $this->ing['telur']->product->update(['use_as_ingredient' => false]);

        $this->voidAsOwner($id)->assertOk();

        $this->assertSame(100, $this->vBalance('mie'));
        $this->assertSame(200, $this->vBalance('telur'), 'kembali 4 (yang dulu dipotong), bukan 18');
        $returns = $this->recipeMoves('telur', ['recipe_return']);
        $this->assertCount(1, $returns);
        $this->assertSame(4, (int) $returns->first()->qty_change);
        $this->assertSame('order_item', $returns->first()->reference_type);
    }

    public function test_a_refund_restores_product_ingredients_and_does_not_change_the_line_cost(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);
        $id = $this->sale(3);
        $cost = (float) OrderItem::where('order_id', $id)->value('cost_price');

        $this->refund($id)->assertOk();

        $this->assertSame(100, $this->vBalance('mie'));
        $this->assertSame(200, $this->vBalance('telur'));
        $this->assertEquals($cost, (float) OrderItem::where('order_id', $id)->value('cost_price'));
    }

    public function test_a_second_void_or_refund_returns_nothing_more(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        $id = $this->sale(2);
        $this->refund($id)->assertOk();
        $this->refund($id)->assertStatus(409);
        // Void sesudah refund saat ini tidak ditolak oleh OrderService (perilaku lama, di luar 093); yang dijamin di sini:
        // pemulihan bahan produk membaca buku besar (sale − return = 0), jadi tidak ada pengembalian kedua.
        $this->voidAsOwner($id);

        $this->assertSame(200, $this->vBalance('telur'));
        $this->assertCount(1, $this->recipeMoves('telur', ['recipe_return']));
    }

    public function test_restoring_an_order_that_was_never_deducted_changes_nothing(): void
    {
        $id = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated()->json('id'); // tanpa resep
        $this->voidAsOwner($id)->assertOk();

        $this->assertSame(0, $this->recipeMoves('telur')->count());
        $this->assertSame(200, $this->vBalance('telur'));
    }

    public function test_a_wasted_line_writes_recipe_waste_and_a_later_void_never_brings_it_back(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        $id = $this->sale(1);
        $line = OrderItem::where('order_id', $id)->firstOrFail();

        app(ModifierMaterialConsumption::class)->wasteLine($line, $this->cashier);

        $waste = $this->recipeMoves('telur', ['recipe_waste'])->sole();
        $this->assertSame(-1, (int) $waste->qty_change);
        $this->assertSame($line->id, (int) $waste->reference_id);
        $this->assertSame(198, $this->vBalance('telur'), '200 − 1 (jual) − 1 (buang)');

        $this->voidAsOwner($id)->assertOk();
        $this->assertSame(199, $this->vBalance('telur'), 'hanya potongan penjualan yang kembali; yang dibuang tidak');
    }
}
