<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\License;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 019-billing-system (third expansion, research.md R14) — activate()
 * tidak lagi divalidasi lewat kode 6 digit yang dikirim ke email klien
 * (desain lama tidak bisa diverifikasi staf penyedia sendiri), digantikan
 * syarat yang memang bisa dicek langsung di sistem yang sama: company
 * ini sudah punya minimal satu Invoice berstatus 'paid'.
 */
class CompanyActivationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    private function onboardPendingCompany(string $username): Company
    {
        $businessType = BusinessType::factory()->create();
        $license = License::factory()->create();
        $actor = User::factory()->create(['role' => 'owner']);

        return app(CompanyOnboardingService::class)->onboard([
            'business_type_id' => $businessType->id,
            'license_id' => $license->id,
            'name' => 'PT Contoh Jaya',
            'address' => null,
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@contoh.com',
            'contact_phone' => null,
            'owner_username' => $username,
            'owner_password' => 'a-strong-password',
        ], $actor);
    }

    public function test_company_with_a_paid_invoice_can_be_activated_and_owner_can_login(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_contoh');
        Invoice::factory()->create(['company_id' => $company->id, 'status' => 'paid']);

        $response = $this->postJson("/api/v1/companies/{$company->id}/activate");

        $response->assertOk()->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['username' => 'owner_contoh', 'is_active' => true]);

        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'owner_contoh', 'password' => 'a-strong-password',
        ]);
        $login->assertOk()->assertJsonStructure(['token']);
    }

    public function test_company_without_a_paid_invoice_cannot_be_activated(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_no_invoice');
        Invoice::factory()->create(['company_id' => $company->id, 'status' => 'unpaid']);

        $response = $this->postJson("/api/v1/companies/{$company->id}/activate");

        $response->assertStatus(409);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'pending_activation']);
    }

    public function test_company_with_no_invoices_at_all_cannot_be_activated(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_zero_invoices');

        $this->postJson("/api/v1/companies/{$company->id}/activate")->assertStatus(409);
    }

    public function test_already_active_company_rejects_re_activation(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->active()->create();

        $this->postJson("/api/v1/companies/{$company->id}/activate")->assertStatus(409);
    }

    public function test_cashier_cannot_activate_a_company(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $company = Company::factory()->create();
        Invoice::factory()->create(['company_id' => $company->id, 'status' => 'paid']);

        $this->postJson("/api/v1/companies/{$company->id}/activate")->assertStatus(403);
    }

    public function test_active_company_can_be_deactivated_and_owner_login_is_locked(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->active()->create();

        $response = $this->postJson("/api/v1/companies/{$company->id}/deactivate");

        $response->assertOk()->assertJsonPath('status', 'pending_activation');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'pending_activation', 'activated_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $company->owner_user_id, 'is_active' => false]);
    }

    public function test_pending_company_cannot_be_deactivated(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_never_activated');

        $this->postJson("/api/v1/companies/{$company->id}/deactivate")->assertStatus(409);
    }

    public function test_cashier_cannot_deactivate_a_company(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $company = Company::factory()->active()->create();

        $this->postJson("/api/v1/companies/{$company->id}/deactivate")->assertStatus(403);
    }

    public function test_deactivated_company_can_be_reactivated_using_its_existing_paid_invoice(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->active()->create();
        Invoice::factory()->create(['company_id' => $company->id, 'status' => 'paid']);

        $this->postJson("/api/v1/companies/{$company->id}/deactivate")->assertOk();
        $this->postJson("/api/v1/companies/{$company->id}/activate")->assertOk()->assertJsonPath('status', 'active');
    }

    public function test_index_exposes_can_activate_flag_based_on_paid_invoices(): void
    {
        $this->actingAsOwner();

        $eligible = $this->onboardPendingCompany('owner_eligible');
        Invoice::factory()->create(['company_id' => $eligible->id, 'status' => 'paid']);

        $notEligible = $this->onboardPendingCompany('owner_not_eligible');
        Invoice::factory()->create(['company_id' => $notEligible->id, 'status' => 'unpaid']);

        $response = $this->getJson('/api/v1/companies');

        $response->assertOk();
        $data = collect($response->json('data'));
        $this->assertTrue($data->firstWhere('id', $eligible->id)['can_activate']);
        $this->assertFalse($data->firstWhere('id', $notEligible->id)['can_activate']);
    }
}
