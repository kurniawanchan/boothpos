<?php

namespace Tests\Feature;

use App\Models\LicenseActivation;
use App\Models\Outlet;
use App\Models\User;
use App\Support\ServerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 065 / US6 — GET /server-address PUBLIK untuk tablet baru: tanpa token, hanya alamat terpilih (alamat satunya tidak
 * pernah keluar), dibatasi laju, dan tetap di balik gerbang lisensi. Rute lama /devices/server-address tetap ada.
 */
class ServerAddressPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_no_token_and_returns_only_the_selected_local_address(): void
    {
        ServerAddress::save(['local' => 'http://192.168.1.219:8010/', 'online' => 'https://pos.example.com', 'selected' => 'local']);

        $res = $this->getJson('/api/v1/server-address')->assertOk()->assertExactJson(['selected' => 'local', 'url' => 'http://192.168.1.219:8010']);
        $this->assertStringNotContainsString('pos.example.com', $res->getContent(), 'alamat yang tidak terpilih tidak boleh keluar');
    }

    public function test_it_returns_only_the_online_address_when_online_is_selected(): void
    {
        ServerAddress::save(['local' => 'http://192.168.1.219:8010', 'online' => 'https://pos.example.com', 'selected' => 'online']);

        $res = $this->getJson('/api/v1/server-address')->assertOk()->assertExactJson(['selected' => 'online', 'url' => 'https://pos.example.com']);
        $this->assertStringNotContainsString('192.168', $res->getContent());
    }

    public function test_the_url_is_null_when_the_chosen_option_has_no_address(): void
    {
        ServerAddress::save(['local' => 'http://192.168.1.219:8010', 'online' => null, 'selected' => 'online']);

        $this->getJson('/api/v1/server-address')->assertOk()->assertExactJson(['selected' => 'online', 'url' => null]);
    }

    public function test_the_defaults_are_local_with_no_address(): void
    {
        $this->getJson('/api/v1/server-address')->assertOk()->assertExactJson(['selected' => 'local', 'url' => null]);
    }

    public function test_it_is_throttled(): void
    {
        foreach (range(1, 30) as $i) {
            $this->getJson('/api/v1/server-address')->assertOk();
        }

        $this->getJson('/api/v1/server-address')->assertStatus(429);
    }

    public function test_it_stays_behind_the_licence_gate(): void
    {
        LicenseActivation::query()->delete();

        $this->getJson('/api/v1/server-address')->assertStatus(423);
    }

    public function test_the_old_device_route_still_works_for_old_apks(): void
    {
        Outlet::first() ?? Outlet::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $device = $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet kasir'])->assertCreated()->json('id');
        ServerAddress::save(['local' => 'http://192.168.1.10:8010', 'online' => null, 'selected' => 'local']);

        $this->getJson('/api/v1/devices/server-address', ['X-Device-Id' => (string) $device])->assertOk()->assertJsonPath('url', 'http://192.168.1.10:8010');
    }
}
