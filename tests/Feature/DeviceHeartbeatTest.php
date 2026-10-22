<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MenuRelease;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 048 / T064 — heartbeat terminal: penyimpanan versi dan waktu terakhir, tanda selisih jam (positif = jam
 * perangkat MAJU), penolakan perangkat tak dikenal/dicabut/outlet lain, validasi, dan menu_release.
 */
class DeviceHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->deviceId = $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet kasir'])->assertCreated()->json('id');

        // Sesi yang mengirim heartbeat adalah kasir biasa (bukan owner/admin): heartbeat tidak dibatasi peran.
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $body */
    private function beat(array $body = [], ?int $device = null, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $body += ['app_version' => '1.4.2', 'clock_ms' => now()->getTimestampMs(), 'queue_depth' => 0];
        $headers += ['X-Device-Id' => (string) ($device ?? $this->deviceId)];

        return $this->postJson('/api/v1/devices/heartbeat', $body, $headers);
    }

    public function test_a_heartbeat_stores_the_app_version_and_the_last_seen_time(): void
    {
        $this->beat(['app_version' => '1.4.2'])->assertOk();

        $device = Device::findOrFail($this->deviceId);
        $this->assertSame('1.4.2', $device->app_version);
        $this->assertNotNull($device->last_seen_at);
    }

    /** 064 — tablet yang dipasang sebelum fitur ini belum menyimpan namanya; heartbeat mengembalikannya. */
    public function test_the_heartbeat_response_carries_the_terminal_name(): void
    {
        $this->beat()->assertOk()->assertJsonPath('name', 'Tablet kasir');
    }

    public function test_a_device_clock_ahead_by_90_seconds_has_a_positive_skew(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC'));
        $serverMs = now()->getTimestampMs();

        $this->beat(['clock_ms' => $serverMs + 90000])
            ->assertOk()
            ->assertJsonPath('skew_seconds', 90)
            ->assertJsonPath('server_time_ms', $serverMs);

        $this->assertSame(90, Device::findOrFail($this->deviceId)->clock_skew_seconds);
    }

    public function test_a_device_clock_behind_by_45_seconds_has_a_negative_skew(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC'));
        $serverMs = now()->getTimestampMs();

        $this->beat(['clock_ms' => $serverMs - 45000])->assertOk()->assertJsonPath('skew_seconds', -45);
        $this->assertSame(-45, Device::findOrFail($this->deviceId)->clock_skew_seconds);
    }

    public function test_an_unknown_device_is_refused_with_device_id_error(): void
    {
        $this->beat([], 999999)->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_a_revoked_device_is_refused(): void
    {
        Device::whereKey($this->deviceId)->update(['revoked_at' => now()]);

        $this->beat()->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_another_outlets_device_is_refused_for_a_cashier_of_this_outlet(): void
    {
        // Terminal di outlet lain, dipasangkan owner di outlet itu.
        $other = Outlet::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $otherDevice = $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet outlet lain'], ['X-Outlet-Id' => $other->id])->assertCreated()->json('id');

        // Kasir hanya ditugaskan ke outlet ini: terminal outlet lain tidak terlihat dan ditolak.
        $cashier = User::factory()->unassigned()->create(['role' => 'cashier']);
        $cashier->outlets()->sync([$this->outlet->id]);
        $this->actingAs($cashier, 'sanctum');

        $this->beat([], $otherDevice, ['X-Outlet-Id' => (string) $this->outlet->id])
            ->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_a_missing_device_header_is_refused(): void
    {
        $this->postJson('/api/v1/devices/heartbeat', ['app_version' => '1.4.2', 'clock_ms' => now()->getTimestampMs(), 'queue_depth' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_the_body_is_validated(): void
    {
        $this->beat(['queue_depth' => -1])->assertStatus(422)->assertJsonValidationErrors('queue_depth');
        $this->beat(['queue_depth' => 10001])->assertStatus(422)->assertJsonValidationErrors('queue_depth');
        $this->beat(['clock_ms' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('clock_ms');
        $this->beat(['app_version' => str_repeat('a', 41)])->assertStatus(422)->assertJsonValidationErrors('app_version');
        $this->postJson('/api/v1/devices/heartbeat', ['clock_ms' => now()->getTimestampMs(), 'queue_depth' => 0], ['X-Device-Id' => (string) $this->deviceId])
            ->assertStatus(422)->assertJsonValidationErrors('app_version');
    }

    public function test_queue_depth_is_accepted_but_not_stored(): void
    {
        $this->beat(['queue_depth' => 42])->assertOk();

        $this->assertFalse(array_key_exists('queue_depth', Device::findOrFail($this->deviceId)->getAttributes()));
    }

    public function test_menu_release_is_null_while_the_release_engine_is_off(): void
    {
        // Rilis terbit ada, tetapi mesin rilis mati (bawaan): versi yang berlaku tetap null.
        MenuRelease::create(['version' => 7, 'status' => MenuRelease::PUBLISHED, 'publish_at' => now(), 'published_at' => now()]);

        $this->beat()->assertOk()->assertJsonPath('menu_release', null);
    }

    public function test_menu_release_is_the_latest_published_version_when_the_engine_is_on(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'string']);
        MenuRelease::create(['version' => 7, 'status' => MenuRelease::PUBLISHED, 'publish_at' => now(), 'published_at' => now()->subDay()]);
        MenuRelease::create(['version' => 9, 'status' => MenuRelease::PUBLISHED, 'publish_at' => now(), 'published_at' => now()]);
        MenuRelease::create(['version' => 10, 'status' => MenuRelease::SCHEDULED, 'publish_at' => now()]);

        $this->beat()->assertOk()->assertJsonPath('menu_release', 9);
    }
}
