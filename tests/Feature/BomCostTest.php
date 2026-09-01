<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomCostTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_bom_line_can_be_attached_to_a_variant(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create();
        $material = Material::factory()->create();

        $response = $this->postJson("/api/v1/variants/{$variant->id}/bom", [
            'material_id' => $material->id,
            'qty_needed' => 2.5,
        ]);

        $response->assertCreated()->assertJsonPath('qty_needed', '2.5000');
    }

    public function test_same_material_cannot_be_added_twice_to_the_same_variant_bom(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create();
        $material = Material::factory()->create();
        $variant->bomLines()->create(['material_id' => $material->id, 'qty_needed' => 1]);

        $response = $this->postJson("/api/v1/variants/{$variant->id}/bom", [
            'material_id' => $material->id,
            'qty_needed' => 3,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('material_id');
    }

    public function test_cost_breakdown_uses_preferred_vendor_price_when_present(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create(['cost_price' => 0]);
        $material = Material::factory()->create();

        // Vendor termurah TAPI tidak preferred — preferred harus menang.
        VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'price' => 5000, 'is_preferred' => false]);
        $preferred = VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'price' => 8000, 'is_preferred' => true]);

        $variant->bomLines()->create(['material_id' => $material->id, 'qty_needed' => 2]);

        $response = $this->getJson("/api/v1/variants/{$variant->id}/cost-breakdown");

        $response->assertOk()
            ->assertJsonPath('bom_cost', '16000.00')
            ->assertJsonPath('lines.0.reference_vendor_id', $preferred->vendor_id)
            ->assertJsonPath('lines.0.unit_cost', '8000.00');
    }

    public function test_cost_breakdown_falls_back_to_cheapest_price_when_no_preferred_vendor(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create(['cost_price' => 0]);
        $material = Material::factory()->create();

        VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'price' => 9000, 'is_preferred' => false]);
        $cheapest = VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'price' => 4000, 'is_preferred' => false]);

        $variant->bomLines()->create(['material_id' => $material->id, 'qty_needed' => 3]);

        $response = $this->getJson("/api/v1/variants/{$variant->id}/cost-breakdown");

        $response->assertOk()
            ->assertJsonPath('bom_cost', '12000.00')
            ->assertJsonPath('lines.0.reference_vendor_id', $cheapest->vendor_id);
    }

    public function test_cost_breakdown_marks_material_without_any_vendor_price(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create(['cost_price' => 0]);
        $material = Material::factory()->create();
        $variant->bomLines()->create(['material_id' => $material->id, 'qty_needed' => 5]);

        $response = $this->getJson("/api/v1/variants/{$variant->id}/cost-breakdown");

        $response->assertOk()
            ->assertJsonPath('bom_cost', '0.00')
            ->assertJsonPath('lines.0.has_price', false);
    }

    public function test_bom_cost_does_not_overwrite_manual_cost_price(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create(['cost_price' => 12345.00]);
        $material = Material::factory()->create();
        VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'price' => 1000, 'is_preferred' => true]);
        $variant->bomLines()->create(['material_id' => $material->id, 'qty_needed' => 1]);

        $response = $this->getJson("/api/v1/variants/{$variant->id}/cost-breakdown");

        $response->assertOk()
            ->assertJsonPath('cost_price', '12345.00')
            ->assertJsonPath('bom_cost', '1000.00');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'cost_price' => 12345.00]);
    }

    public function test_bom_line_can_be_updated_and_deleted(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create();
        $material = Material::factory()->create();
        $line = $variant->bomLines()->create(['material_id' => $material->id, 'qty_needed' => 1]);

        $this->putJson("/api/v1/bom/{$line->id}", ['qty_needed' => 4])
            ->assertOk()->assertJsonPath('qty_needed', '4.0000');

        $this->deleteJson("/api/v1/bom/{$line->id}")->assertStatus(204);
        $this->assertDatabaseMissing('product_variant_bom_lines', ['id' => $line->id]);
    }
}
