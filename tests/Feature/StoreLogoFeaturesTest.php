<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 076 (US4) — GET /settings/features membawa `store_logo_url` untuk SEMUA peran
 * yang login: dipakai SPA untuk logo sidebar dan favicon. Nilainya URL yang
 * sudah diselesaikan server (ImageUploadService), tidak peduli flag
 * `receipt_show_logo` yang hanya berlaku untuk struk.
 */
class StoreLogoFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function setSetting(string $key, mixed $value, string $group = 'receipt'): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string', 'group' => $group]);
    }

    public function test_the_logo_url_is_null_until_a_logo_exists(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('store_logo_url', null);
    }

    public function test_every_signed_in_role_receives_the_resolved_logo_url(): void
    {
        Storage::fake('public');
        $this->setSetting('store_logo_path', 'store-logo/abc.png');
        $expected = Storage::disk('public')->url('store-logo/abc.png');

        foreach (['owner', 'admin', 'cashier', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('store_logo_url', $expected);
        }
    }

    public function test_the_receipt_logo_switch_does_not_hide_the_branding_logo(): void
    {
        Storage::fake('public');
        $this->setSetting('store_logo_path', 'store-logo/abc.png');
        $this->setSetting('receipt_show_logo', 'false');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertOk()
            ->assertJsonPath('receipt_show_logo', false)
            ->assertJsonPath('store_logo_url', Storage::disk('public')->url('store-logo/abc.png'));
    }

    public function test_store_name_follows_the_active_mode_next_to_the_logo(): void
    {
        $this->setSetting('store_name', 'Toko Live');
        $this->setSetting('store_name_demo', 'Toko Demo');
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('store_name', 'Toko Live');

        $this->setSetting('system_mode', 'demo', 'system');
        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('store_name', 'Toko Demo');
    }

    public function test_guests_cannot_read_it(): void
    {
        $this->getJson('/api/v1/settings/features')->assertUnauthorized();
    }
}
