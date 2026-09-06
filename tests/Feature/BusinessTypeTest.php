<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTypeTest extends TestCase
{
    use RefreshDatabase;

    private function ownerHeaders(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    public function test_owner_can_create_business_type(): void
    {
        $this->ownerHeaders();

        $response = $this->postJson('/api/v1/business-types', ['name' => 'Retail', 'code' => 'retail']);

        $response->assertCreated()->assertJsonPath('code', 'RETAIL');
        $this->assertDatabaseHas('business_types', ['code' => 'RETAIL', 'name' => 'Retail']);
    }

    public function test_owner_can_update_and_deactivate_business_type(): void
    {
        $this->ownerHeaders();
        $businessType = BusinessType::factory()->create(['is_active' => true]);

        $response = $this->putJson("/api/v1/business-types/{$businessType->id}", ['is_active' => false]);

        $response->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_deleting_a_business_type_referenced_by_a_company_is_rejected(): void
    {
        $this->ownerHeaders();
        $businessType = BusinessType::factory()->create();
        Company::factory()->create(['business_type_id' => $businessType->id]);

        $response = $this->deleteJson("/api/v1/business-types/{$businessType->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('business_types', ['id' => $businessType->id, 'deleted_at' => null]);
    }

    public function test_deleting_an_unreferenced_business_type_succeeds(): void
    {
        $this->ownerHeaders();
        $businessType = BusinessType::factory()->create();

        $response = $this->deleteJson("/api/v1/business-types/{$businessType->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('business_types', ['id' => $businessType->id]);
    }

    public function test_cashier_cannot_create_business_type(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $response = $this->postJson('/api/v1/business-types', ['name' => 'Retail', 'code' => 'RETAIL']);

        $response->assertStatus(403);
        $this->getJson('/api/v1/business-types')->assertStatus(403);
    }
}
