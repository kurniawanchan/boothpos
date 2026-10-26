<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 077 / US1+US2 — nama toko, logo, dan mode data untuk tablet SEBELUM login. Rute tanpa token (pengecualian Konstitusi IV,
 * plan.md → Complexity Tracking), jadi tes ini juga membuktikan apa yang TIDAK keluar, dan bahwa nilainya sama dengan
 * yang dibaca peran yang login lewat `GET /settings/features` (satu sumber: StoreBranding).
 */
class PosSetupBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string']);
    }

    public function test_branding_is_readable_without_a_token_and_returns_only_three_keys(): void
    {
        $this->set('store_name', 'Warkop WJM');
        $this->set('store_logo_path', 'store-logo/abc123.png');

        $res = $this->getJson('/api/v1/pos-setup/branding')->assertOk();

        $this->assertEqualsCanonicalizing(['system_mode', 'store_name', 'store_logo_url'], array_keys($res->json()));
        $res->assertJsonPath('system_mode', 'live')->assertJsonPath('store_name', 'Warkop WJM');
        $this->assertStringEndsWith('/storage/store-logo/abc123.png', $res->json('store_logo_url'));
    }

    public function test_logo_is_null_when_none_was_uploaded(): void
    {
        $this->getJson('/api/v1/pos-setup/branding')->assertOk()->assertJsonPath('store_logo_url', null);
    }

    public function test_demo_mode_reports_demo_and_uses_the_demo_store_name(): void
    {
        $this->set('store_name', 'Nama LIVE');
        $this->set('store_name_demo', 'Nama DEMO');
        $this->set('system_mode', 'demo');

        $this->getJson('/api/v1/pos-setup/branding')->assertOk()
            ->assertJsonPath('system_mode', 'demo')->assertJsonPath('store_name', 'Nama DEMO');
    }

    public function test_values_match_what_a_logged_in_role_reads_from_features(): void
    {
        $this->set('store_name', 'Warkop WJM');
        $this->set('store_logo_path', 'store-logo/abc123.png');
        $this->set('system_mode', 'demo');
        $this->set('store_name_demo', 'Nama DEMO');

        $public = $this->getJson('/api/v1/pos-setup/branding')->json();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $features = $this->getJson('/api/v1/settings/features')->json();

        $this->assertSame($features['system_mode'], $public['system_mode']);
        $this->assertSame($features['store_name'], $public['store_name']);
        $this->assertSame($features['store_logo_url'], $public['store_logo_url']);
    }
}
