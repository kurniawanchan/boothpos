<?php

namespace Tests\Feature;

use App\Models\MaterialStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 2026-10-31 (permintaan pemilik: "koreksi stok bahan masuk log Inventori → Stok") — GET /materials/movements:
 * buku besar SEMUA bahan, dibatasi outlet yang terlihat, dan alasan koreksi tersimpan di pergerakannya sendiri
 * (dulu hanya di deskripsi log aktivitas).
 */
class MaterialStockLedgerTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_an_adjustment_keeps_its_reason_on_the_movement_and_shows_in_the_ledger_of_all_materials(): void
    {
        $this->postJson("/api/v1/materials/{$this->mat['telur']->id}/adjustments", ['outlet_id' => $this->outletA->id, 'qty_change' => 5, 'reason' => 'Opname pagi'])->assertCreated();
        $this->postJson("/api/v1/materials/{$this->mat['soto']->id}/adjustments", ['outlet_id' => $this->outletB->id, 'qty_change' => -2, 'reason' => 'Rusak'])->assertCreated();

        $this->assertSame('Opname pagi', MaterialStockMovement::where('material_id', $this->mat['telur']->id)->latest('id')->value('reason'));

        $rows = $this->getJson('/api/v1/materials/movements?type[]=adjustment')->assertOk()->json('data');
        $this->assertSame(['Indomie Soto', 'Telur'], array_slice(array_column($rows, 'material_name'), 0, 2));
        $this->assertSame(['Rusak', 'Opname pagi'], array_slice(array_column($rows, 'reason'), 0, 2));
        $this->assertSame('butir', $rows[1]['unit']);

        // Outlet navbar menyempitkan buku besar.
        $narrowed = $this->withHeader('X-Outlet-Id', (string) $this->outletA->id)->getJson('/api/v1/materials/movements?type[]=adjustment')->assertOk()->json('data');
        $this->assertNotContains($this->outletB->id, array_column($narrowed, 'outlet_id'));
        $this->assertContains('Opname pagi', array_column($narrowed, 'reason'));

        $this->getJson('/api/v1/materials/movements?material_id='.$this->mat['soto']->id)->assertOk()
            ->assertJsonPath('data.0.material_name', 'Indomie Soto');
    }

    /**
     * 2026-11-01 — layar "Stok Bahan" tablet (hanya baca) dipakai kasir yang memegang menu `pos` saja; buku besar ini
     * hanya baca, dibatasi outlet, dan tanpa angka biaya. Tanpa `pos`/`materials`/`stock` tetap ditolak.
     */
    public function test_pos_staff_may_read_the_ledger_but_others_without_the_menus_are_refused(): void
    {
        $this->actingAs($this->cashier, 'sanctum');
        $this->getJson('/api/v1/materials/movements')->assertOk();

        $role = \App\Models\Role::factory()->create(['menu_keys' => ['dashboard', 'customers']]);
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'role_id' => $role->id]), 'sanctum');
        $this->getJson('/api/v1/materials/movements')->assertForbidden();
    }
}
