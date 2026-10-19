<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 054 / US3 — alamat server lokal/online: akses admin saja (FR-011), validasi 422 (FR-012), audit dalam transaksi
 * (constitution IV), dan respons perangkat yang hanya memuat pilihan aktif (research.md R3).
 */
class ServerAddressTest extends TestCase
{
    use RefreshDatabase;

    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        Outlet::first() ?? Outlet::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->deviceId = $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet kasir'])->assertCreated()->json('id');
    }

    public function test_defaults_are_empty_addresses_and_local_selected(): void
    {
        $this->getJson('/api/v1/settings/server-address')
            ->assertOk()
            ->assertExactJson(['local' => null, 'online' => null, 'selected' => 'local']);
    }

    public function test_owner_saves_both_addresses_and_the_choice(): void
    {
        $this->putJson('/api/v1/settings/server-address', [
            'local' => 'http://192.168.1.10:8010/',
            'online' => 'https://pos.example.com',
            'selected' => 'online',
        ])->assertOk()->assertExactJson([
            'local' => 'http://192.168.1.10:8010',
            'online' => 'https://pos.example.com',
            'selected' => 'online',
        ]);

        $this->getJson('/api/v1/settings/server-address')->assertJsonPath('selected', 'online');
    }

    public function test_cashier_cannot_read_or_change_the_addresses(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/server-address')->assertForbidden();
        $this->putJson('/api/v1/settings/server-address', ['selected' => 'local'])->assertForbidden();
    }

    public function test_an_address_without_a_scheme_is_refused_and_nothing_is_saved(): void
    {
        $this->putJson('/api/v1/settings/server-address', [
            'online' => 'pos.example.com',
            'selected' => 'local',
        ])->assertUnprocessable()->assertJsonValidationErrors(['online']);

        $this->getJson('/api/v1/settings/server-address')->assertJsonPath('online', null);
    }

    public function test_selecting_online_without_an_online_address_is_refused(): void
    {
        $this->putJson('/api/v1/settings/server-address', ['local' => 'http://192.168.1.10:8010', 'selected' => 'online'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['selected']);

        $this->getJson('/api/v1/settings/server-address')->assertJsonPath('selected', 'local');
    }

    public function test_an_unknown_choice_is_refused(): void
    {
        $this->putJson('/api/v1/settings/server-address', ['selected' => 'cloud'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['selected']);
    }

    public function test_a_change_writes_one_audit_row_with_before_and_after(): void
    {
        $this->putJson('/api/v1/settings/server-address', ['local' => 'http://192.168.1.10:8010', 'selected' => 'local'])->assertOk();

        $log = ActivityLog::query()->where('action', 'server_address_updated')->sole();
        $this->assertSame('setting', $log->entity_type);
        $this->assertNull($log->old_values['local']);
        $this->assertSame('http://192.168.1.10:8010', $log->new_values['local']);
    }

    public function test_a_refused_change_writes_no_audit_row(): void
    {
        $this->putJson('/api/v1/settings/server-address', ['selected' => 'cloud'])->assertUnprocessable();

        $this->assertSame(0, ActivityLog::query()->where('action', 'server_address_updated')->count());
    }

    public function test_the_device_gets_only_the_selected_address(): void
    {
        $this->putJson('/api/v1/settings/server-address', [
            'local' => 'http://192.168.1.10:8010',
            'online' => 'https://pos.example.com',
            'selected' => 'online',
        ])->assertOk();

        $this->getJson('/api/v1/devices/server-address', ['X-Device-Id' => (string) $this->deviceId])
            ->assertOk()
            ->assertExactJson(['selected' => 'online', 'url' => 'https://pos.example.com']);
    }

    public function test_the_device_gets_a_null_url_when_local_is_selected_but_not_set(): void
    {
        $this->getJson('/api/v1/devices/server-address', ['X-Device-Id' => (string) $this->deviceId])
            ->assertOk()
            ->assertExactJson(['selected' => 'local', 'url' => null]);
    }

    public function test_a_missing_device_header_is_refused(): void
    {
        $this->getJson('/api/v1/devices/server-address')->assertUnprocessable()->assertJsonValidationErrors(['device_id']);
    }

    public function test_a_revoked_device_is_refused(): void
    {
        $this->deleteJson('/api/v1/devices/'.$this->deviceId)->assertNoContent();

        $this->getJson('/api/v1/devices/server-address', ['X-Device-Id' => (string) $this->deviceId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_an_unknown_device_is_refused(): void
    {
        $this->getJson('/api/v1/devices/server-address', ['X-Device-Id' => '999999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_id']);
    }
}
