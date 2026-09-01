<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_create_material(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/materials', [
            'code' => 'AC3',
            'name' => 'Acrylic sheet 3mm',
            'unit' => 'lembar',
        ]);

        $response->assertCreated()->assertJsonPath('code', 'AC3')->assertJsonPath('unit', 'lembar');
    }

    public function test_cashier_cannot_create_material(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->postJson('/api/v1/materials', [
            'code' => 'AC3',
            'name' => 'Acrylic sheet 3mm',
            'unit' => 'lembar',
        ]);

        $response->assertStatus(403);
    }

    public function test_material_with_vendor_price_cannot_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $material = Material::factory()->create();
        VendorMaterialPrice::factory()->create(['material_id' => $material->id]);

        $this->deleteJson("/api/v1/materials/{$material->id}")->assertStatus(409);
    }

    public function test_material_used_in_bom_cannot_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $material = Material::factory()->create();
        $variant = ProductVariant::factory()->create();
        ProductVariantBomLine::factory()->create(['material_id' => $material->id, 'product_variant_id' => $variant->id]);

        $this->deleteJson("/api/v1/materials/{$material->id}")->assertStatus(409);
    }

    public function test_material_without_references_can_be_deleted(): void
    {
        $this->actingAsRole('owner');
        $material = Material::factory()->create();

        $this->deleteJson("/api/v1/materials/{$material->id}")->assertStatus(204);
    }

    // --- Vendor pricing (which vendors, plural, and their prices) ------

    public function test_material_can_have_multiple_vendor_prices(): void
    {
        $this->actingAsRole('owner');
        $material = Material::factory()->create();
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();

        $this->postJson("/api/v1/materials/{$material->id}/vendor-prices", [
            'vendor_id' => $vendorA->id,
            'price' => 10000,
        ])->assertCreated();

        $this->postJson("/api/v1/materials/{$material->id}/vendor-prices", [
            'vendor_id' => $vendorB->id,
            'price' => 8000,
        ])->assertCreated();

        $this->assertDatabaseCount('vendor_material_prices', 2);
    }

    public function test_same_vendor_cannot_be_attached_twice_to_the_same_material(): void
    {
        $this->actingAsRole('owner');
        $material = Material::factory()->create();
        $vendor = Vendor::factory()->create();
        VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'vendor_id' => $vendor->id]);

        $response = $this->postJson("/api/v1/materials/{$material->id}/vendor-prices", [
            'vendor_id' => $vendor->id,
            'price' => 5000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('vendor_id');
    }

    public function test_marking_a_vendor_price_preferred_unmarks_the_others(): void
    {
        $this->actingAsRole('owner');
        $material = Material::factory()->create();
        $priceA = VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'is_preferred' => true, 'price' => 9000]);
        $vendorB = Vendor::factory()->create();

        $response = $this->postJson("/api/v1/materials/{$material->id}/vendor-prices", [
            'vendor_id' => $vendorB->id,
            'price' => 7000,
            'is_preferred' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('vendor_material_prices', ['id' => $priceA->id, 'is_preferred' => false]);
    }

    public function test_vendor_price_can_be_updated_and_deleted(): void
    {
        $this->actingAsRole('owner');
        $price = VendorMaterialPrice::factory()->create(['price' => 1000]);

        $this->putJson("/api/v1/vendor-prices/{$price->id}", ['price' => 1500])
            ->assertOk()->assertJsonPath('price', '1500.00');

        $this->deleteJson("/api/v1/vendor-prices/{$price->id}")->assertStatus(204);
        $this->assertDatabaseMissing('vendor_material_prices', ['id' => $price->id]);
    }
}
