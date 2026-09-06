<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    public function test_owner_can_create_package(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/packages', [
            'name' => 'Starter', 'description' => 'Paket dasar', 'license_tier' => 'pro',
        ]);

        $response->assertCreated()->assertJsonPath('license_tier', 'pro');
        $this->assertDatabaseHas('packages', ['name' => 'Starter', 'license_tier' => 'pro']);
    }

    public function test_deactivated_package_excluded_from_active_only_list_but_still_shown_on_existing_company(): void
    {
        $this->actingAsOwner();
        $package = Package::factory()->create(['is_active' => true]);
        $company = Company::factory()->create(['package_id' => $package->id]);

        $package->update(['is_active' => false]);

        $activeList = $this->getJson('/api/v1/packages?is_active=1')->json('data');
        $this->assertEmpty(array_filter($activeList, fn ($p) => $p['id'] === $package->id));

        $companyResponse = $this->getJson("/api/v1/companies/{$company->id}");
        $companyResponse->assertOk()->assertJsonPath('package.id', $package->id);
    }

    public function test_deleting_a_package_referenced_by_a_company_is_rejected(): void
    {
        $this->actingAsOwner();
        $package = Package::factory()->create();
        Company::factory()->create(['package_id' => $package->id]);

        $response = $this->deleteJson("/api/v1/packages/{$package->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('packages', ['id' => $package->id, 'deleted_at' => null]);
    }

    public function test_cashier_cannot_access_packages(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->postJson('/api/v1/packages', ['name' => 'X', 'license_tier' => 'pro'])->assertStatus(403);
        $this->getJson('/api/v1/packages')->assertStatus(403);
    }
}
