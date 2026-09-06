<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
        $package = Package::factory()->create();

        return array_merge([
            'business_type_id' => $businessType->id,
            'package_id' => $package->id,
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
        $this->assertDatabaseHas('company_activation_notifications', ['trigger' => 'created']);
    }

    public function test_onboarding_rejects_duplicate_owner_username(): void
    {
        $this->actingAsOwner();
        User::factory()->create(['username' => 'owner_contoh']);

        $response = $this->postJson('/api/v1/companies', $this->basePayload(['owner_username' => 'owner_contoh']));

        $response->assertStatus(422)->assertJsonValidationErrors('owner_username');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_onboarding_with_unconfigured_mail_records_skipped_notification(): void
    {
        Config::set('mail.default', 'log');
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/companies', $this->basePayload());

        $response->assertCreated();
        $this->assertDatabaseHas('company_activation_notifications', [
            'trigger' => 'created',
            'status' => 'skipped_not_configured',
        ]);
    }

    public function test_cashier_cannot_onboard_company(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->postJson('/api/v1/companies', $this->basePayload())->assertStatus(403);
    }
}
