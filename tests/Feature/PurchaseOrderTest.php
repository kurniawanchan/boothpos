<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_create_a_purchase_order_with_mixed_line_items(): void
    {
        $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();
        $material = Material::factory()->create();
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor->id,
            'items' => [
                ['line_type' => 'material', 'material_id' => $material->id, 'product_id' => $product->id, 'qty' => 10, 'unit_price' => 5000],
                ['line_type' => 'service', 'description' => 'Ongkos kirim', 'qty' => 1, 'unit_price' => 20000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('total_amount', '70000.00')
            ->assertJsonPath('items.0.material_id', $material->id)
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.1.description', 'Ongkos kirim');
    }

    public function test_material_line_without_material_id_is_rejected(): void
    {
        $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();

        $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor->id,
            'items' => [['line_type' => 'material', 'qty' => 1, 'unit_price' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.material_id');
    }

    public function test_service_line_without_description_is_rejected(): void
    {
        $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();

        $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor->id,
            'items' => [['line_type' => 'service', 'qty' => 1, 'unit_price' => 1000]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.description');
    }

    public function test_owner_can_list_and_view_purchase_orders(): void
    {
        $owner = $this->actingAsRole('owner');
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id]);

        $this->getJson('/api/v1/purchase-orders')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk()->assertJsonPath('id', $po->id);
    }

    public function test_draft_purchase_order_can_be_updated_and_deleted(): void
    {
        $owner = $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();
        $material = Material::factory()->create();
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'vendor_id' => $vendor->id, 'status' => 'draft']);
        $po->items()->create(['line_type' => 'material', 'material_id' => $material->id, 'qty' => 1, 'unit_price' => 1000, 'line_total' => 1000]);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'items' => [['line_type' => 'material', 'material_id' => $material->id, 'qty' => 5, 'unit_price' => 2000]],
        ])->assertOk()->assertJsonPath('total_amount', '10000.00');

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertNoContent();
        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
    }

    public function test_non_draft_purchase_order_rejects_item_updates_and_deletion(): void
    {
        $owner = $this->actingAsRole('owner');
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'ordered']);

        $this->putJson("/api/v1/purchase-orders/{$po->id}", [
            'items' => [['line_type' => 'service', 'description' => 'x', 'qty' => 1, 'unit_price' => 1000]],
        ])->assertStatus(409);

        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(409);
    }

    public function test_a_role_without_purchase_orders_access_is_forbidden(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id]);

        $this->actingAsRole('cashier');
        $vendor = Vendor::factory()->create();

        // Bukan hanya tulis — baca (index/show) juga digerbang, karena PO
        // membawa harga beli aktual, bukan sekadar harga acuan vendor
        // (lihat komentar PurchaseOrderPolicy).
        $this->getJson('/api/v1/purchase-orders')->assertStatus(403);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(403);
        $this->postJson('/api/v1/purchase-orders', ['vendor_id' => $vendor->id, 'items' => []])->assertStatus(403);
    }

    public function test_a_role_with_purchase_orders_access_can_view(): void
    {
        $owner = $this->actingAsRole('inventory');
        $po = PurchaseOrder::factory()->create(['created_by' => $owner->id]);

        $this->getJson('/api/v1/purchase-orders')->assertOk();
        $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk();
    }

    public function test_activity_logger_records_creation_and_deletion_inside_the_same_transaction(): void
    {
        $owner = $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();
        $material = Material::factory()->create();

        $response = $this->postJson('/api/v1/purchase-orders', [
            'vendor_id' => $vendor->id,
            'items' => [['line_type' => 'material', 'material_id' => $material->id, 'qty' => 1, 'unit_price' => 1000]],
        ]);
        $poId = $response->json('id');

        $this->assertDatabaseHas('activity_logs', ['entity_type' => 'PurchaseOrder', 'entity_id' => $poId, 'action' => 'created']);

        $this->deleteJson("/api/v1/purchase-orders/{$poId}")->assertNoContent();
        $this->assertDatabaseHas('activity_logs', ['entity_type' => 'PurchaseOrder', 'entity_id' => $poId, 'action' => 'deleted']);
    }
}
