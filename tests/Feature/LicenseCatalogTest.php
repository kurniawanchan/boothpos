<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 019-billing-system — rename dari PackageTest.php (research.md R1', R2').
 * T041 menambah cakupan validasi price/payment_type dan memastikan
 * gerbang menu-key sudah `licenses`, bukan lagi `companies` peninggalan
 * 017 — plus verifikasi baris seed Pro/Master dari T036/T037.
 */
class LicenseCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    public function test_owner_can_create_license(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/licenses', [
            'name' => 'Starter', 'description' => 'Lisensi dasar', 'license_tier' => 'pro',
            'price' => 500000, 'payment_type' => 'one_time',
        ]);

        $response->assertCreated()->assertJsonPath('license_tier', 'pro');
        $this->assertDatabaseHas('licenses', ['name' => 'Starter', 'license_tier' => 'pro']);
    }

    public function test_deactivated_license_excluded_from_active_only_list_but_still_shown_on_existing_company(): void
    {
        $this->actingAsOwner();
        $license = License::factory()->create(['is_active' => true]);
        $company = Company::factory()->create(['license_id' => $license->id]);

        $license->update(['is_active' => false]);

        $activeList = $this->getJson('/api/v1/licenses?is_active=1')->json('data');
        $this->assertEmpty(array_filter($activeList, fn ($p) => $p['id'] === $license->id));

        $companyResponse = $this->getJson("/api/v1/companies/{$company->id}");
        $companyResponse->assertOk()->assertJsonPath('license.id', $license->id);
    }

    public function test_deleting_a_license_referenced_by_a_company_is_rejected(): void
    {
        $this->actingAsOwner();
        $license = License::factory()->create();
        Company::factory()->create(['license_id' => $license->id]);

        $response = $this->deleteJson("/api/v1/licenses/{$license->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('licenses', ['id' => $license->id, 'deleted_at' => null]);
    }

    public function test_cashier_cannot_access_licenses(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $this->postJson('/api/v1/licenses', ['name' => 'X', 'license_tier' => 'pro', 'price' => 1, 'payment_type' => 'one_time'])->assertStatus(403);
        $this->getJson('/api/v1/licenses')->assertStatus(403);
    }

    /**
     * T041 — inventory juga tidak boleh, karena menu key `licenses` HANYA
     * diberikan ke Owner/Admin oleh migrasi
     * 2026_10_19_000010_add_licenses_menu_key_to_default_roles (research.md
     * R2'), berbeda dari menu `companies` lama yang dipakai PackagePolicy
     * sebelum rename ini — memastikan gerbangnya benar-benar sudah pindah,
     * bukan cuma nama policy-nya yang berubah.
     */
    public function test_inventory_cannot_access_licenses(): void
    {
        $inventory = User::factory()->create(['role' => 'inventory']);
        $this->actingAs($inventory, 'sanctum');

        $this->getJson('/api/v1/licenses')->assertStatus(403);
        $this->postJson('/api/v1/licenses', ['name' => 'X', 'license_tier' => 'pro', 'price' => 1, 'payment_type' => 'one_time'])->assertStatus(403);
    }

    public function test_creating_license_requires_price_and_payment_type(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/licenses', [
            'name' => 'Tanpa Harga', 'license_tier' => 'pro',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'payment_type']);
    }

    public function test_creating_license_rejects_invalid_payment_type(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson('/api/v1/licenses', [
            'name' => 'Salah Tipe Bayar', 'license_tier' => 'pro',
            'price' => 100000, 'payment_type' => 'installment',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['payment_type']);
    }

    public function test_creating_license_accepts_one_time_and_subscription_payment_types(): void
    {
        $this->actingAsOwner();

        foreach (['one_time', 'subscription'] as $paymentType) {
            $response = $this->postJson('/api/v1/licenses', [
                'name' => "Lisensi {$paymentType}", 'license_tier' => 'master',
                'price' => 750000, 'payment_type' => $paymentType,
            ]);

            $response->assertCreated()->assertJsonPath('payment_type', $paymentType);
        }
    }

    /**
     * T036/T037 — memastikan seeder DatabaseSeeder::seedLicenses()
     * benar-benar menghasilkan tepat dua baris Pro/Master, idempotent
     * terhadap baris yang sudah ada (nama dikunci, tidak menimpa harga
     * yang sudah diubah owner/admin) — research.md R9'.
     */
    public function test_seeded_pro_and_master_licenses_are_visible_via_index(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->actingAsOwner();

        $response = $this->getJson('/api/v1/licenses?per_page=100');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Pro'));
        $this->assertTrue($names->contains('Master'));

        $this->assertDatabaseHas('licenses', ['name' => 'Pro', 'license_tier' => 'pro', 'payment_type' => 'subscription']);
        $this->assertDatabaseHas('licenses', ['name' => 'Master', 'license_tier' => 'master', 'payment_type' => 'one_time']);
    }
}
