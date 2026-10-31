<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\TestCase;

/**
 * 087 US3 (T035) — void dan refund pesanan selesai mengembalikan potongan bersih dari buku besar (FR-019, FR-020).
 */
class MaterialRestoreTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function sale(int $qty = 2, ?array $opts = null): int
    {
        return $this->checkout([[$this->indomie, $qty, $opts ?? $this->opts('soto', 'extra', 'telur')]])->assertCreated()->json('id');
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

    public function test_a_refund_returns_exactly_what_was_deducted(): void
    {
        $id = $this->sale();
        $cost = (float) OrderItem::where('order_id', $id)->value('cost_price');

        $this->refund($id)->assertOk();

        $this->assertSame(50.0, $this->balance('soto'));
        $this->assertSame(100.0, $this->balance('cabai'));
        $this->assertSame(100.0, $this->balance('telur'));
        $returns = $this->consumptionMovements('return');
        $this->assertCount(3, $returns);
        $this->assertTrue($returns->every(fn ($m) => $m->reference_type === 'order_item'));
        $this->assertEquals($cost, (float) OrderItem::where('order_id', $id)->value('cost_price'), 'refund tidak mengubah HPP baris');
    }

    public function test_a_void_returns_what_was_deducted_even_if_the_mapping_changed_or_the_material_was_deactivated(): void
    {
        $id = $this->sale();
        $this->opt['telur']->materials()->update(['quantity' => 9]);
        $this->opt['soto']->materials()->delete();
        $this->mat['cabai']->update(['is_active' => false]);

        $this->voidAsOwner($id)->assertOk();

        $this->assertSame(50.0, $this->balance('soto'));
        $this->assertSame(100.0, $this->balance('cabai'));
        $this->assertSame(100.0, $this->balance('telur'));
    }

    public function test_a_second_refund_or_void_is_refused_and_returns_nothing_more(): void
    {
        $id = $this->sale();
        $this->refund($id)->assertOk();
        $this->refund($id)->assertStatus(409);

        $this->assertCount(3, $this->consumptionMovements('return'));
        $this->assertSame(100.0, $this->balance('telur'));
    }

    public function test_orders_without_mapped_options_restore_nothing(): void
    {
        $id = $this->sale(1, $this->opts('ayam', 'sedang'));
        $this->opt['ayam']->materials()->delete();
        $before = $this->consumptionMovements()->count();

        // Baris AYAM sudah dipotong saat jual (pemetaan masih ada saat itu) → kembali; Sedang tanpa bahan → tidak ada.
        $this->refund($id)->assertOk();
        $this->assertSame($before + 1, $this->consumptionMovements()->count());
        $this->assertSame(50.0, $this->balance('ayam'));
    }
}
