<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_with_valid_credentials(): void
    {
        User::factory()->create([
            'username' => 'kasir01',
            'password' => Hash::make('secret123'),
            'role' => 'cashier',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'kasir01',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'username', 'role']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'kasir01',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'kasir01',
            'password' => 'salah',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        User::factory()->create([
            'username' => 'nonaktif',
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonaktif',
            'password' => 'secret123',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_login_error_message_does_not_reveal_whether_username_exists(): void
    {
        // Negative test OWASP — user enumeration. Username tidak terdaftar
        // dan password salah pada username terdaftar harus menghasilkan
        // pesan yang identik.
        User::factory()->create(['username' => 'ada', 'password' => Hash::make('secret123')]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'username' => 'ada', 'password' => 'salah',
        ])->json('message');

        $unknownUser = $this->postJson('/api/v1/auth/login', [
            'username' => 'tidak_ada', 'password' => 'apapun',
        ])->json('message');

        $this->assertSame($wrongPassword, $unknownUser);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('username', $user->username);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(204);

        // Token yang sama tidak lagi valid untuk permintaan berikutnya.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
