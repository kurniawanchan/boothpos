<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 003-seed-demo-live T023 (US2) — endpoint `system_mode` via infrastruktur
 * Settings yang sudah ada (lihat contracts/settings-system-mode.md):
 * tidak ada rute baru, hanya field baru di features() dan validasi baru
 * di UpdateSettingsRequest.
 */
class SettingsSystemModeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_features_endpoint_defaults_to_live_on_a_fresh_install(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->getJson('/api/v1/settings/features');

        $response->assertOk()->assertJsonPath('system_mode', 'live');
    }

    public function test_owner_can_switch_system_mode_to_demo(): void
    {
        $this->actingAsRole('owner');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'system_mode', 'value' => 'demo', 'type' => 'string', 'group' => 'system']],
        ])->assertOk();

        $this->assertSame('demo', ModeGate::current());
        $this->getJson('/api/v1/settings/features')->assertJsonPath('system_mode', 'demo');
    }

    public function test_invalid_system_mode_value_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $response = $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'system_mode', 'value' => 'sandbox', 'type' => 'string', 'group' => 'system']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('settings.0.value');
        $this->assertSame('live', ModeGate::current());
    }

    public function test_cashier_cannot_change_system_mode_but_can_still_read_it(): void
    {
        $this->actingAsRole('cashier');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'system_mode', 'value' => 'demo', 'type' => 'string', 'group' => 'system']],
        ])->assertStatus(403);
        $this->assertSame('live', ModeGate::current());

        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('system_mode', 'live');
    }

    public function test_inventory_cannot_change_system_mode(): void
    {
        $this->actingAsRole('inventory');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'system_mode', 'value' => 'demo', 'type' => 'string', 'group' => 'system']],
        ])->assertStatus(403);
    }

    public function test_switching_mode_writes_an_activity_log_entry(): void
    {
        $owner = $this->actingAsRole('owner');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'system_mode', 'value' => 'demo', 'type' => 'string', 'group' => 'system']],
        ])->assertOk();

        $log = ActivityLog::where('entity_type', 'Setting')
            ->where('description', "Mengubah pengaturan 'system_mode'.")
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
    }

    public function test_admin_can_switch_mode_back_to_live(): void
    {
        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);
        $this->actingAsRole('admin');

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'system_mode', 'value' => 'live', 'type' => 'string', 'group' => 'system']],
        ])->assertOk();

        $this->assertSame('live', ModeGate::current());
    }
}
