<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Services\PinAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 073 / US2 — roster dan login PIN TANPA token (tablet belum login), hanya staf kasir/inventory, terikat ke terminal
 * yang dipilih, kunci 5-salah selama 5 menit. Sengaja TIDAK memakai actingAs: yang dibuktikan di sini adalah rute
 * tanpa token itu sendiri.
 */
class PinLoginTerminalTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $cashier;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->cashier = $this->withPin(User::factory()->create(['role' => 'cashier']), '482916');
        $this->cashier->outlets()->sync([$this->outlet->id]);
        $this->device = $this->terminal('Kasir Depan');
    }

    private function withPin(User $u, string $pin): User
    {
        $u->forceFill(['pin_hash' => Hash::make($pin)])->save();

        return $u;
    }

    private function terminal(string $name, ?Outlet $outlet = null, bool $revoked = false): Device
    {
        $d = new Device(['name' => $name, 'paired_at' => now()]);
        $d->setAttribute('outlet_id', ($outlet ?? $this->outlet)->id);
        if ($revoked) {
            $d->revoked_at = now();
        }
        $d->save();

        return $d;
    }

    private function login(string $pin, ?int $userId = null, ?int $deviceId = null, bool $withDevice = true)
    {
        $body = ['outlet_id' => $this->outlet->id, 'user_id' => $userId ?? $this->cashier->id, 'pin' => $pin];
        if ($withDevice) {
            $body['device_id'] = $deviceId ?? $this->device->id;
        }

        return $this->postJson('/api/v1/auth/pin-sessions', $body);
    }

    public function test_roster_needs_no_token_and_shows_only_what_the_picker_needs(): void
    {
        $this->getJson('/api/v1/staff/roster?outlet_id='.$this->outlet->id)->assertOk()
            ->assertJsonFragment(['id' => $this->cashier->id, 'has_pin' => true, 'locked' => false])
            ->assertJsonMissingPath('data.0.username')->assertJsonMissingPath('data.0.pin_hash');
    }

    public function test_roster_excludes_owner_and_admin_but_keeps_an_all_outlets_role_without_settings(): void
    {
        $owner = $this->withPin(User::factory()->create(['role' => 'owner']), '482916');
        $admin = $this->withPin(User::factory()->create(['role' => 'admin']), '482916');
        $inventory = $this->withPin(User::factory()->create(['role' => 'inventory']), '482916');
        $supervisor = Role::create(['name' => 'Supervisor', 'menu_keys' => ['pos', 'session'], 'all_outlets' => true]);
        $super = $this->withPin(User::factory()->create(['role_id' => $supervisor->id]), '482916');

        $ids = collect($this->getJson('/api/v1/staff/roster?outlet_id='.$this->outlet->id)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertNotContains($owner->id, $ids);
        $this->assertNotContains($admin->id, $ids);
        $this->assertContains($inventory->id, $ids);
        $this->assertContains($this->cashier->id, $ids);
        $this->assertContains($super->id, $ids, 'peran semua-outlet TANPA menu settings bukan owner/admin');
    }

    public function test_owner_and_admin_cannot_sign_in_by_pin_and_are_not_counted(): void
    {
        $owner = $this->withPin(User::factory()->create(['role' => 'owner']), '482916');

        $this->login('482916', $owner->id)->assertStatus(401)->assertJsonPath('code', 'invalid_pin')->assertJsonMissingPath('attempts_remaining');
        $this->login('000111', $owner->id)->assertStatus(401);
        $this->assertSame(0, $owner->fresh()->pin_failed_attempts);
    }

    public function test_correct_pin_signs_in_without_a_token_and_logs_user_outlet_and_terminal(): void
    {
        $res = $this->login('482916')->assertOk()->assertJsonPath('user.id', $this->cashier->id);
        $this->assertNotEmpty($res->json('token'));

        $log = ActivityLog::where('action', 'pin_login')->sole();
        $this->assertSame($this->cashier->id, (int) $log->user_id);
        $this->assertStringContainsString('Kasir Depan', $log->description);
        $this->assertStringContainsString($this->outlet->name, $log->description);
    }

    public function test_the_terminal_is_required(): void
    {
        $this->login('482916', withDevice: false)->assertStatus(422)->assertJsonValidationErrors('device_id');
        $this->assertSame(0, ActivityLog::where('action', 'pin_login')->count());
    }

    public function test_a_terminal_of_another_outlet_a_revoked_one_or_an_unknown_one_is_422_and_does_not_count_the_pin(): void
    {
        $other = Outlet::factory()->create();
        $elsewhere = $this->terminal('Kasir Lain', $other);
        $revoked = $this->terminal('Kasir Dicabut', revoked: true);

        foreach ([$elsewhere->id, $revoked->id, 999999] as $deviceId) {
            $this->login('000111', deviceId: $deviceId)->assertStatus(422)->assertJsonValidationErrors('device_id');
        }

        $this->assertSame(0, $this->cashier->fresh()->pin_failed_attempts);
        $this->assertSame(0, ActivityLog::where('action', 'pin_login')->count());
    }

    public function test_five_wrong_pins_lock_the_user_for_five_minutes_on_every_terminal(): void
    {
        $second = $this->terminal('Kasir Belakang');

        foreach (range(1, 4) as $_) {
            $this->login('000111')->assertStatus(401);
        }
        $res = $this->login('000111')->assertStatus(429)->assertJsonPath('code', 'pin_locked');

        $this->assertTrue($this->cashier->fresh()->pin_locked_until->between(now()->addMinutes(4), now()->addMinutes(5)->addSecond()));
        $this->assertNotEmpty($res->json('locked_until'));
        // Kunci milik pengguna, bukan terminal: terminal lain pun ditolak, bahkan dengan PIN benar.
        $this->login('482916', deviceId: $second->id)->assertStatus(429);

        $this->travel(6)->minutes();
        $this->login('482916', deviceId: $second->id)->assertOk();
    }

    public function test_a_correct_pin_resets_the_wrong_pin_count(): void
    {
        foreach (range(1, 3) as $_) {
            $this->login('000111')->assertStatus(401);
        }
        $this->login('482916')->assertOk();

        $this->assertSame(0, $this->cashier->fresh()->pin_failed_attempts);
    }

    public function test_the_approver_pin_shares_the_same_five_minute_lock(): void
    {
        $service = app(PinAuthService::class);
        $approver = $this->withPin(User::factory()->create(['role' => 'cashier']), '482916');

        foreach (range(1, 5) as $_) {
            $service->verifyApproverPin($approver->fresh(), '000111');
        }

        $this->assertTrue($approver->fresh()->pin_locked_until->between(now()->addMinutes(4), now()->addMinutes(5)->addSecond()));
    }
}
