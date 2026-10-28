<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 079 / US4 — nama toko tablet SEBELUM login mengikuti outlet yang dipilih tablet. Rute tanpa token (lanjutan
 * pengecualian Konstitusi IV 077): outlet dari path, hanya tiga nilai yang keluar — mode, nama outlet, logo bersama.
 */
class PosSetupOutletBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_outlet_branding_returns_the_outlets_name_and_the_shared_logo_without_a_token(): void
    {
        Setting::updateOrCreate(['key' => 'store_logo_path'], ['value' => 'store-logo/abc123.png', 'type' => 'string']);
        $outlet = Outlet::factory()->create(['name' => 'Kedai Satu', 'address' => 'Jl. Rahasia 1', 'is_active' => true]);

        $res = $this->getJson("/api/v1/pos-setup/outlets/{$outlet->id}/branding")->assertOk();

        $this->assertEqualsCanonicalizing(['system_mode', 'store_name', 'store_logo_url'], array_keys($res->json()));
        $res->assertJsonPath('store_name', 'Kedai Satu')->assertJsonPath('system_mode', 'live');
        $this->assertStringEndsWith('/storage/store-logo/abc123.png', $res->json('store_logo_url'));
        $this->assertStringNotContainsString('Jl. Rahasia', $res->getContent());
    }

    public function test_an_unknown_or_inactive_outlet_is_a_404(): void
    {
        $inactive = Outlet::factory()->create(['is_active' => false]);

        $this->getJson("/api/v1/pos-setup/outlets/{$inactive->id}/branding")->assertNotFound();
        $this->getJson('/api/v1/pos-setup/outlets/999999/branding')->assertNotFound();
    }

    public function test_the_unkeyed_branding_route_still_returns_the_shared_name(): void
    {
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Toko Bersama', 'type' => 'string']);
        Outlet::factory()->create(['name' => 'Kedai Satu', 'is_active' => true]);

        $this->getJson('/api/v1/pos-setup/branding')->assertOk()->assertJsonPath('store_name', 'Toko Bersama');
    }

    public function test_an_outlet_without_a_name_in_the_shared_settings_still_returns_its_own_name(): void
    {
        $outlet = Outlet::factory()->create(['name' => 'Kedai Dua', 'is_active' => true]);

        $this->getJson("/api/v1/pos-setup/outlets/{$outlet->id}/branding")->assertOk()->assertJsonPath('store_name', 'Kedai Dua');
    }
}
