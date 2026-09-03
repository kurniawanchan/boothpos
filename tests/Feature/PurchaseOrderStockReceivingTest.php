<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderStockReceivingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function poWithMaterialLine(User $owner, Material $material, float $qty): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'ordered']);
        $po->items()->create([
            'line_type' => 'material', 'material_id' => $material->id,
            'qty' => $qty, 'unit_price' => 1000, 'line_total' => $qty * 1000,
        ]);

        return $po;
    }

    public function test_receiving_increases_material_stock_and_writes_a_movement_row(): void
    {
        $owner = $this->actingAsRole('owner');
        $material = Material::factory()->create(['current_stock' => 5]);
        $po = $this->poWithMaterialLine($owner, $material, 10);

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertOk();

        $this->assertEquals(15, (float) $material->fresh()->current_stock);
        $this->assertDatabaseHas('material_stock_movements', [
            'material_id' => $material->id,
            'type' => 'purchase',
            'qty_change' => 10,
            'stock_before' => 5,
            'stock_after' => 15,
        ]);
    }

    public function test_receiving_twice_is_rejected_and_stock_is_not_double_applied(): void
    {
        $owner = $this->actingAsRole('owner');
        $material = Material::factory()->create(['current_stock' => 0]);
        $po = $this->poWithMaterialLine($owner, $material, 10);

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertOk();
        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertStatus(409);

        $this->assertEquals(10, (float) $material->fresh()->current_stock);
        $this->assertEquals(1, \App\Models\MaterialStockMovement::where('material_id', $material->id)->count());
    }

    public function test_service_line_items_have_no_stock_effect(): void
    {
        $owner = $this->actingAsRole('owner');
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'ordered']);
        $po->items()->create(['line_type' => 'service', 'description' => 'Ongkir', 'qty' => 1, 'unit_price' => 20000, 'line_total' => 20000]);

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertOk();

        $this->assertEquals(0, \App\Models\MaterialStockMovement::count());
    }

    public function test_receiving_a_demo_mode_purchase_order_only_affects_demo_mode_stock(): void
    {
        $owner = $this->actingAsRole('owner');
        $material = ModeGate::runAs('demo', fn () => Material::factory()->create(['current_stock' => 0]));
        $po = ModeGate::runAs('demo', function () use ($owner, $material) {
            $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'ordered']);
            $po->items()->create(['line_type' => 'material', 'material_id' => $material->id, 'qty' => 7, 'unit_price' => 1000, 'line_total' => 7000]);

            return $po;
        });

        // Sesi HTTP saat ini berjalan di mode aktif default (live) —
        // memastikan endpoint hanya bisa menjangkau PO ini via mode yang
        // sama tempat ia dibuat (DataModeScope), bukan lintas mode diam-diam.
        $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(404);

        ModeGate::runAs('demo', function () use ($po, $material) {
            $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertOk();
            $this->assertEquals(7, (float) $material->fresh()->current_stock);
        });
    }
}
