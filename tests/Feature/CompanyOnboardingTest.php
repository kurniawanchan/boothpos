<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function basePayload(array $overrides = []): array
    {
        $businessType = BusinessType::factory()->create();
        $license = License::factory()->create();

        return array_merge([
            'business_type_id' => $businessType->id,
            'license_id' => $license->id,
            'name' => 'PT Contoh Jaya',
            'address' => 'Jl. Merdeka No. 1',
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@contoh.com',
            'contact_phone' => '0812-3456-7890',
            'owner_username' => 'owner_contoh',
            'owner_password' => 'a-strong-password',
        ], $overrides);
    }

    public function test_onboarding_creates_pending_company_and_inactive_owner_user(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/companies', $this->basePayload());

        $response->assertCreated()
            ->assertJsonPath('status', 'pending_activation')
            ->assertJsonPath('owner_username', 'owner_contoh');

        $this->assertDatabaseHas('users', ['username' => 'owner_contoh', 'is_active' => false]);
    }

    public function test_onboarding_rejects_duplicate_owner_username(): void
    {
        $this->actingAsOwner();
        User::factory()->create(['username' => 'owner_contoh']);

        $response = $this->postJson('/api/v1/companies', $this->basePayload(['owner_username' => 'owner_contoh']));

        $response->assertStatus(422)->assertJsonValidationErrors('owner_username');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_cashier_cannot_onboard_company(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->postJson('/api/v1/companies', $this->basePayload())->assertStatus(403);
    }

    // 019-billing-system (second expansion, T091) — edit/delete Company.

    public function test_owner_can_update_a_company_and_changes_persist(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();
        $newBusinessType = BusinessType::factory()->create();
        $newLicense = License::factory()->create();

        $response = $this->putJson("/api/v1/companies/{$company->id}", [
            'business_type_id' => $newBusinessType->id,
            'license_id' => $newLicense->id,
            'name' => 'PT Berubah Nama',
            'address' => 'Jl. Baru No. 2',
            'contact_name' => 'Siti Aminah',
            'contact_email' => 'siti@contoh.com',
            'contact_phone' => '0813-1111-2222',
        ]);

        $response->assertOk()->assertJsonPath('name', 'PT Berubah Nama');

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'PT Berubah Nama',
            'business_type_id' => $newBusinessType->id,
            'license_id' => $newLicense->id,
            'contact_email' => 'siti@contoh.com',
        ]);
    }

    public function test_owner_can_delete_a_company_with_zero_invoices(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();

        $response = $this->deleteJson("/api/v1/companies/{$company->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_owner_cannot_delete_a_company_that_has_an_invoice(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();
        Invoice::factory()->create(['company_id' => $company->id]);

        $response = $this->deleteJson("/api/v1/companies/{$company->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'deleted_at' => null]);
    }

    public function test_cashier_cannot_update_or_delete_company(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $company = Company::factory()->create();

        $this->putJson("/api/v1/companies/{$company->id}", [
            'business_type_id' => $company->business_type_id,
            'license_id' => $company->license_id,
            'name' => 'Nama Lain',
            'contact_name' => 'Contact Lain',
            'contact_email' => 'lain@contoh.com',
        ])->assertStatus(403);

        $this->deleteJson("/api/v1/companies/{$company->id}")->assertStatus(403);
    }
}
