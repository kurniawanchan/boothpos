<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_create_vendor(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/vendors', [
            'code' => 'vn-acrylic',
            'name' => 'Toko Akrilik Jaya',
            'contact_phone' => '08123456789',
        ]);

        $response->assertCreated()->assertJsonPath('code', 'VN-ACRYLIC');
    }

    public function test_cashier_cannot_create_vendor(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->postJson('/api/v1/vendors', [
            'code' => 'VN1',
            'name' => 'Toko A',
        ]);

        $response->assertStatus(403);
    }

    public function test_inventory_role_can_create_vendor(): void
    {
        $this->actingAsRole('inventory');

        $response = $this->postJson('/api/v1/vendors', [
            'code' => 'VN2',
            'name' => 'Toko B',
        ]);

        $response->assertCreated();
    }

    public function test_vendor_code_must_be_unique(): void
    {
        $this->actingAsRole('owner');
        Vendor::factory()->create(['code' => 'VN1']);

        $response = $this->postJson('/api/v1/vendors', [
            'code' => 'vn1',
            'name' => 'Toko Lain',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_vendor_code_cannot_be_changed_on_update(): void
    {
        $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create(['code' => 'VN1']);

        $response = $this->putJson("/api/v1/vendors/{$vendor->id}", [
            'code' => 'VN2',
            'name' => 'Nama Baru',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'code' => 'VN1', 'name' => 'Nama Baru']);
    }

    public function test_vendor_list_uses_pagination_envelope(): void
    {
        $this->actingAsRole('owner');
        Vendor::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/vendors');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }

    public function test_vendor_with_registered_material_price_cannot_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();
        $material = Material::factory()->create();
        VendorMaterialPrice::factory()->create(['vendor_id' => $vendor->id, 'material_id' => $material->id]);

        $response = $this->deleteJson("/api/v1/vendors/{$vendor->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'deleted_at' => null]);
    }

    public function test_vendor_without_material_prices_can_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $vendor = Vendor::factory()->create();

        $this->deleteJson("/api/v1/vendors/{$vendor->id}")->assertStatus(204);
    }
}
