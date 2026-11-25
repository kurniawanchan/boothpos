<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 094 / FR-005a, kontrak A2 — token aplikasi manajemen (`manager-app`) diperiksa ULANG tiap permintaan: penanda peran yang
 * dimatikan di web mencabut sesi aplikasi pada permintaan berikutnya. Token web (`pos-session`) dan PIN (`pos-pin`) milik
 * pengguna yang sama tidak terpengaruh.
 */
class ManagerAppAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role = Role::factory()->create(['name' => 'Outlet Manager Tes', 'menu_keys' => ['dashboard', 'products'], 'manager_app_enabled' => true]);
        $this->user = User::factory()->create(['role_id' => $this->role->id]);
    }

    private function me(string $token)
    {
        // Lupakan pengguna yang di-cache guard antar-permintaan dalam satu tes.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me');
    }

    public function test_a_manager_app_token_works_while_the_flag_is_on(): void
    {
        $token = $this->user->createToken('manager-app')->plainTextToken;

        $this->me($token)->assertOk()->assertJsonPath('id', $this->user->id);
    }

    public function test_turning_the_flag_off_revokes_the_manager_app_token_on_the_next_request(): void
    {
        $token = $this->user->createToken('manager-app')->plainTextToken;
        $this->me($token)->assertOk();

        $this->role->update(['manager_app_enabled' => false]);

        $this->me($token)->assertStatus(403)->assertJsonPath('code', 'manager_app_denied');
        $this->assertSame(0, $this->user->tokens()->where('name', 'manager-app')->count());
    }

    public function test_web_and_pin_tokens_of_the_same_user_are_not_affected(): void
    {
        $web = $this->user->createToken('pos-session')->plainTextToken;
        $pin = $this->user->createToken('pos-pin')->plainTextToken;
        $this->role->update(['manager_app_enabled' => false]);

        $this->me($web)->assertOk();
        $this->me($pin)->assertOk();
    }
}
