<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 094 / FR-004–FR-010, kontrak A1 — `POST /auth/manager-sessions`: login aplikasi manajemen. Kredensial dan jatah percobaan
 * SAMA dengan login web (CredentialLogin); setelah kredensial benar, peran wajib memegang `manager_app_enabled` dan outlet
 * pilihan harus termasuk outlet yang boleh dilihat pengguna. Token bernama `manager-app`.
 */
class ManagerSessionTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
    }

    private function managerRole(bool $enabled = true): Role
    {
        return Role::factory()->create([
            'name' => 'Outlet Manager '.uniqid(),
            'menu_keys' => ['dashboard', 'products', 'stock'],
            'manager_app_enabled' => $enabled,
        ]);
    }

    private function user(Role $role, string $password = 'rahasia-123', array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'mgr'.uniqid(), 'role_id' => $role->id, 'password' => Hash::make($password),
        ], $extra));
    }

    private function login(string $username, string $password, ?int $outletId = null)
    {
        return $this->postJson('/api/v1/auth/manager-sessions', [
            'username' => $username, 'password' => $password, 'outlet_id' => $outletId ?? $this->outlet->id,
        ]);
    }

    public function test_a_role_with_the_flag_gets_a_manager_app_token_and_the_user_payload(): void
    {
        $user = $this->user($this->managerRole());

        $res = $this->login($user->username, 'rahasia-123')->assertOk();

        $res->assertJsonStructure(['token', 'user' => ['id', 'username', 'role', 'menu_keys', 'permissions']]);
        $this->assertSame(['manager-app'], $user->tokens()->pluck('name')->all());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_unknown_user_and_inactive_account_get_401_without_a_token(): void
    {
        $user = $this->user($this->managerRole());
        $inactive = $this->user($this->managerRole(), 'rahasia-123', ['is_active' => false]);

        $wrong = $this->login($user->username, 'salah')->assertStatus(401);
        $unknown = $this->login('tidak-ada-orangnya', 'salah')->assertStatus(401);
        $this->login($inactive->username, 'rahasia-123')->assertStatus(401);

        // Pesan seragam: tidak membocorkan username mana yang ada.
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
        $this->assertSame(0, $user->tokens()->count() + $inactive->tokens()->count());
    }

    public function test_a_role_without_the_flag_is_refused_with_403_and_no_token(): void
    {
        $cashier = $this->user($this->managerRole(false));

        $this->login($cashier->username, 'rahasia-123')
            ->assertStatus(403)
            ->assertJsonPath('code', 'manager_app_denied');

        $this->assertSame(0, $cashier->tokens()->count());
    }

    public function test_an_outlet_the_user_is_not_assigned_to_is_refused(): void
    {
        $other = Outlet::factory()->create(['is_active' => true]);
        $user = $this->user($this->managerRole());
        $user->outlets()->sync([$this->outlet->id]);

        $this->login($user->username, 'rahasia-123', $other->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'outlet_not_permitted');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_all_outlets_role_may_pick_any_active_outlet(): void
    {
        $other = Outlet::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'username' => 'owner-mgr', 'password' => Hash::make('rahasia-123')]);

        $this->login($owner->username, 'rahasia-123', $other->id)->assertOk();
    }

    public function test_an_unknown_or_inactive_outlet_is_a_validation_error(): void
    {
        $user = $this->user($this->managerRole());
        $inactive = Outlet::factory()->create(['is_active' => false]);

        $this->login($user->username, 'rahasia-123', 999999)->assertStatus(422)->assertJsonValidationErrors('outlet_id');
        $this->login($user->username, 'rahasia-123', $inactive->id)->assertStatus(422)->assertJsonValidationErrors('outlet_id');
    }

    public function test_failed_attempts_share_the_web_login_allowance(): void
    {
        $user = $this->user($this->managerRole());

        // 3 gagal di web + 2 gagal di aplikasi = 5 → percobaan ke-6 (dari mana pun) ditolak 429, walau benar.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'salah'])->assertStatus(401);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->login($user->username, 'salah')->assertStatus(401);
        }

        $this->login($user->username, 'rahasia-123')->assertStatus(429)->assertJsonPath('code', 'throttled');
    }
}
