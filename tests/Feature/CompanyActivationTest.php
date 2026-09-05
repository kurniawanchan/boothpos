<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Company;
use App\Models\Package;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CompanyActivationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    /**
     * Onboards via the service (not HTTP) so the test controls the actor,
     * then overwrites the hash with a code the test fully controls
     * (Hash::make is one-way, so there's no other way to get a known
     * plaintext code to submit against the real /activate endpoint).
     */
    private function onboardPendingCompany(string $username): Company
    {
        $businessType = BusinessType::factory()->create();
        $package = Package::factory()->create();
        $actor = User::factory()->create(['role' => 'owner']);

        return app(CompanyOnboardingService::class)->onboard([
            'business_type_id' => $businessType->id,
            'package_id' => $package->id,
            'name' => 'PT Contoh Jaya',
            'address' => null,
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@contoh.com',
            'contact_phone' => null,
            'owner_username' => $username,
            'owner_password' => 'a-strong-password',
        ], $actor);
    }

    public function test_correct_code_activates_company_and_owner_can_login(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_contoh');

        $knownCode = '654321';
        $company->update(['activation_code_hash' => Hash::make($knownCode)]);

        $response = $this->postJson("/api/v1/companies/{$company->id}/activate", ['code' => $knownCode]);

        $response->assertOk()->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['username' => 'owner_contoh', 'is_active' => true]);

        $login = $this->postJson('/api/v1/auth/login', [
            'username' => 'owner_contoh', 'password' => 'a-strong-password',
        ]);
        $login->assertOk()->assertJsonStructure(['token']);
    }

    public function test_wrong_code_is_rejected_and_company_stays_pending(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_wrong_code');

        $response = $this->postJson("/api/v1/companies/{$company->id}/activate", ['code' => '000000']);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'pending_activation']);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_expired');

        $knownCode = '111222';
        $company->update([
            'activation_code_hash' => Hash::make($knownCode),
            'activation_code_expires_at' => now()->subHour(),
        ]);

        $response = $this->postJson("/api/v1/companies/{$company->id}/activate", ['code' => $knownCode]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_already_active_company_rejects_activation_and_resend(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->active()->create();

        $this->postJson("/api/v1/companies/{$company->id}/activate", ['code' => '123456'])
            ->assertStatus(422);
        $this->postJson("/api/v1/companies/{$company->id}/resend-activation")
            ->assertStatus(409);
    }

    public function test_resend_invalidates_the_previous_code(): void
    {
        $this->actingAsOwner();
        $company = $this->onboardPendingCompany('owner_resend');

        $oldCode = '999888';
        $company->update(['activation_code_hash' => Hash::make($oldCode)]);

        $this->postJson("/api/v1/companies/{$company->id}/resend-activation")->assertOk();

        // The previous code is no longer valid after resend.
        $response = $this->postJson("/api/v1/companies/{$company->id}/activate", ['code' => $oldCode]);
        $response->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('company_activation_notifications', [
            'company_id' => $company->id, 'trigger' => 'resend',
        ]);
    }
}
