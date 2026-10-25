<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 048 / api-gap B1 — roster staf, login PIN, kunci 5-salah, ganti/reset PIN, kedaluwarsa & perpanjang token.
 */
class PinLoginTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $cashier;
    private User $terminal;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'password' => Hash::make('secret-pass-1')]);
        $this->cashier->outlets()->sync([$this->outlet->id]);
        $this->cashier->forceFill(['pin_hash' => Hash::make('482916')])->save();

        // 073 — login PIN kini terikat ke terminal yang dipilih (device_id wajib).
        $this->device = new Device(['name' => 'Kasir Depan', 'paired_at' => now()]);
        $this->device->setAttribute('outlet_id', $this->outlet->id);
        $this->device->save();

        // Token owner/admin tetap dipakai tes ganti/reset PIN (rute itu masih berotentikasi); roster dan login PIN tidak butuh.
        $this->terminal = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->terminal, 'sanctum');
    }

    private function pinLogin(string $pin, ?int $userId = null)
    {
        return $this->postJson('/api/v1/auth/pin-sessions', [
            'outlet_id' => $this->outlet->id, 'user_id' => $userId ?? $this->cashier->id, 'pin' => $pin, 'device_id' => $this->device->id,
        ]);
    }

    public function test_roster_lists_staff_without_secrets(): void
    {
        $this->getJson('/api/v1/staff/roster?outlet_id='.$this->outlet->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $this->cashier->id, 'has_pin' => true, 'locked' => false])
            ->assertJsonMissingPath('data.0.username')
            ->assertJsonMissingPath('data.0.pin_hash');
    }

    public function test_roster_excludes_staff_of_other_outlets_and_inactive_users(): void
    {
        $other = Outlet::factory()->create();
        $elsewhere = User::factory()->create(['role' => 'cashier']);
        $elsewhere->outlets()->sync([$other->id]);
        $inactive = User::factory()->create(['role' => 'cashier', 'is_active' => false]);
        $inactive->outlets()->sync([$this->outlet->id]);

        $ids = collect($this->getJson('/api/v1/staff/roster?outlet_id='.$this->outlet->id)->json('data'))->pluck('id');

        $this->assertContains($this->cashier->id, $ids->all());
        $this->assertNotContains($elsewhere->id, $ids->all());
        $this->assertNotContains($inactive->id, $ids->all());
    }

    public function test_correct_pin_issues_an_expiring_token_for_that_user(): void
    {
        $res = $this->pinLogin('482916')->assertOk()->assertJsonPath('user.id', $this->cashier->id);

        $token = $this->cashier->tokens()->where('name', 'pos-pin')->firstOrFail();
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addHours(11), now()->addHours(12)->addMinute()));
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_wrong_pin_is_401_and_reports_remaining_attempts(): void
    {
        $this->pinLogin('000111')->assertStatus(401)->assertJsonPath('code', 'invalid_pin')->assertJsonPath('attempts_remaining', 4);
        $this->assertSame(1, $this->cashier->fresh()->pin_failed_attempts);
    }

    public function test_five_wrong_pins_lock_for_5_minutes_and_even_the_right_pin_is_refused(): void
    {
        foreach (range(1, 4) as $_) {
            $this->pinLogin('000111')->assertStatus(401);
        }
        $this->pinLogin('000111')->assertStatus(429)->assertJsonPath('code', 'pin_locked');

        $this->pinLogin('482916')->assertStatus(429);
        $this->assertTrue($this->cashier->fresh()->pin_locked_until->between(now()->addMinutes(4), now()->addMinutes(5)->addSecond()));
        $this->assertSame(1, ActivityLog::where('action', 'pin_locked')->count());

        $this->travel(6)->minutes();
        $this->pinLogin('482916')->assertOk();
    }

    public function test_a_successful_login_clears_the_failure_counter(): void
    {
        $this->pinLogin('000111');
        $this->pinLogin('482916')->assertOk();

        $this->assertSame(0, $this->cashier->fresh()->pin_failed_attempts);
    }

    public function test_the_lock_does_not_block_password_login(): void
    {
        $this->cashier->forceFill(['pin_locked_until' => now()->addMinutes(10)])->save();

        $this->postJson('/api/v1/auth/login', ['username' => $this->cashier->username, 'password' => 'secret-pass-1'])->assertOk();
    }

    public function test_user_outside_the_outlet_gets_the_same_generic_401_without_counting(): void
    {
        $other = User::factory()->unassigned()->create(['role' => 'cashier']);
        $other->forceFill(['pin_hash' => Hash::make('482916')])->save();

        $this->pinLogin('482916', $other->id)->assertStatus(401)->assertJsonPath('code', 'invalid_pin')->assertJsonMissingPath('attempts_remaining');
        $this->assertSame(0, $other->fresh()->pin_failed_attempts);
    }

    public function test_pin_must_be_exactly_six_digits(): void
    {
        $this->pinLogin('12345')->assertStatus(422);
        $this->pinLogin('12345a')->assertStatus(422);
    }

    public function test_user_without_a_pin_cannot_log_in_by_pin(): void
    {
        $this->cashier->forceFill(['pin_hash' => null])->save();

        $this->pinLogin('482916')->assertStatus(401);
    }

    public function test_first_pin_is_set_with_the_password_and_weak_pins_are_refused(): void
    {
        $this->cashier->forceFill(['pin_hash' => null])->save();
        $this->actingAs($this->cashier, 'sanctum');

        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '111111', 'current_password' => 'secret-pass-1'])
            ->assertStatus(422)->assertJsonPath('code', 'pin_too_weak');
        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '123456', 'current_password' => 'secret-pass-1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '654321', 'current_password' => 'secret-pass-1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '482916', 'current_password' => 'wrong'])->assertStatus(401);

        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '739204', 'current_password' => 'secret-pass-1'])->assertOk();
        $this->assertTrue(Hash::check('739204', $this->cashier->fresh()->pin_hash));
    }

    public function test_changing_an_existing_pin_needs_the_current_pin_and_wrong_guesses_count(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '739204'])->assertStatus(422)->assertJsonPath('code', 'current_pin_required');
        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '739204', 'current_pin' => '000111'])->assertStatus(401);
        $this->assertSame(1, $this->cashier->fresh()->pin_failed_attempts);

        $this->postJson('/api/v1/auth/pin-changes', ['new_pin' => '739204', 'current_pin' => '482916'])->assertOk();
        $this->assertTrue(Hash::check('739204', $this->cashier->fresh()->pin_hash));
    }

    public function test_admin_reset_clears_pin_lock_and_pin_sessions(): void
    {
        $this->pinLogin('482916')->assertOk();
        $this->cashier->forceFill(['pin_locked_until' => now()->addMinutes(5)])->save();

        $this->postJson("/api/v1/users/{$this->cashier->id}/pin-reset")->assertNoContent();

        $fresh = $this->cashier->fresh();
        $this->assertNull($fresh->pin_hash);
        $this->assertNull($fresh->pin_locked_until);
        $this->assertSame(0, $fresh->tokens()->where('name', 'pos-pin')->count());
        $this->assertSame(1, ActivityLog::where('action', 'pin_reset')->count());
    }

    public function test_only_owner_or_admin_may_reset_a_pin(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $this->postJson("/api/v1/users/{$this->cashier->id}/pin-reset")->assertForbidden();
    }

    public function test_pin_token_expires_and_can_be_refreshed_once_valid(): void
    {
        $token = $this->pinLogin('482916')->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $refreshed = $this->withToken($token)->postJson('/api/v1/auth/token-refresh')->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->assertNotSame($token, $refreshed);

        // Token lama dicabut oleh perpanjangan.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->travel(13)->hours();
        $this->withToken($refreshed)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_tokens_cannot_be_refreshed(): void
    {
        $this->postJson('/api/v1/auth/token-refresh')->assertForbidden();
    }
}
