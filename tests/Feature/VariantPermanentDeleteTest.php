<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 2026-10-31 (permintaan pemilik) — hapus varian PERMANEN dari laci Ubah item dan laci Varian & harga, HANYA bila
 * varian itu belum pernah dipakai (pesanan, pergerakan stok, saldo outlet ≠ 0, baris transfer). Varian yang punya
 * riwayat tetap hanya bisa dinonaktifkan. Varian terakhir sebuah item tidak bisa dihapus.
 */
class VariantPermanentDeleteTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function extraVariant(string $name = 'KARI AYAM'): ProductVariant
    {
        return $this->indomie->product->variants()->create(['sku' => strtoupper(Str::random(10)), 'variant_name' => $name, 'sell_price' => 15000, 'cost_price' => 0, 'current_stock' => 0, 'is_active' => false]);
    }

    public function test_an_unused_variant_is_deleted_for_good_and_logged(): void
    {
        $kari = $this->extraVariant();
        $this->actingAs($this->owner, 'sanctum');

        $this->deleteJson("/api/v1/variants/{$kari->id}/permanent")->assertOk();

        $this->assertNull(ProductVariant::withTrashed()->find($kari->id));
        $log = ActivityLog::where('action', 'variant_deleted')->sole();
        $this->assertSame($kari->id, $log->entity_id);
        $this->assertSame(1, $this->indomie->product->variants()->count());
    }

    public function test_a_variant_that_was_sold_is_refused_and_kept(): void
    {
        $this->extraVariant();
        $this->checkout([[$this->indomie, 1, $this->opts('soto')]])->assertCreated();
        $this->actingAs($this->owner, 'sanctum');

        $this->deleteJson("/api/v1/variants/{$this->indomie->id}/permanent")->assertStatus(409)->assertJsonPath('code', 'variant_in_use');
        $this->assertNotNull(ProductVariant::find($this->indomie->id));
    }

    public function test_a_variant_with_stock_history_is_refused(): void
    {
        $kari = $this->extraVariant();
        app(StockService::class)->applyMovement(variant: $kari, outletId: $this->outletA->id, type: 'adjustment', qtyChange: 3, reason: 'x');
        $this->actingAs($this->owner, 'sanctum');

        $this->deleteJson("/api/v1/variants/{$kari->id}/permanent")->assertStatus(409)->assertJsonPath('code', 'variant_in_use');
    }

    public function test_the_last_variant_of_an_item_is_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->deleteJson("/api/v1/variants/{$this->nasiGoreng->id}/permanent")->assertStatus(409)->assertJsonPath('code', 'last_variant');
    }

    public function test_a_cashier_cannot_delete_variants(): void
    {
        $kari = $this->extraVariant();
        $this->actingAs($this->cashier, 'sanctum');

        $this->deleteJson("/api/v1/variants/{$kari->id}/permanent")->assertForbidden();
        $this->assertNotNull(ProductVariant::find($kari->id));
    }
}
