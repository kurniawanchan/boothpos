<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_switch_language_back_and_forth(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'language' => 'en']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/auth/language', ['language' => 'id'])
            ->assertOk()
            ->assertJsonPath('language', 'id');
        $this->assertSame('id', $user->fresh()->language);

        $this->putJson('/api/v1/auth/language', ['language' => 'en'])
            ->assertOk()
            ->assertJsonPath('language', 'en');
        $this->assertSame('en', $user->fresh()->language);
    }

    public function test_invalid_language_value_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/auth/language', ['language' => 'fr'])
            ->assertStatus(422);
    }

    // FR-003/FR-004 — kasir sengaja TIDAK diberi akses menu 'users', tapi
    // tetap harus bisa mengganti bahasanya sendiri. Endpoint ini SENGAJA
    // tidak digerbang UserPolicy (lihat UpdateLanguageRequest).
    public function test_a_user_without_users_menu_access_can_still_change_own_language(): void
    {
        $kasir = User::factory()->create(['role' => 'cashier']);
        $this->assertFalse($kasir->canAccessMenu('users'));
        $this->actingAs($kasir, 'sanctum');

        $this->putJson('/api/v1/auth/language', ['language' => 'id'])
            ->assertOk()
            ->assertJsonPath('language', 'id');
    }

    public function test_updated_language_is_reflected_on_me_without_relogin(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'language' => 'en']);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/auth/language', ['language' => 'id'])->assertOk();

        $this->getJson('/api/v1/auth/me')->assertJsonPath('language', 'id');
    }

    public function test_two_users_language_preferences_are_independent(): void
    {
        $userA = User::factory()->create(['role' => 'owner', 'language' => 'en']);
        $userB = User::factory()->create(['role' => 'admin', 'language' => 'en']);

        $this->actingAs($userA, 'sanctum');
        $this->putJson('/api/v1/auth/language', ['language' => 'id'])->assertOk();

        $this->assertSame('id', $userA->fresh()->language);
        $this->assertSame('en', $userB->fresh()->language);
    }

    // US2 — akun baru tanpa preferensi eksplisit default English lewat
    // default kolom database, bukan lewat kode aplikasi tambahan.
    public function test_new_user_created_via_user_controller_defaults_to_english(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $role = \App\Models\Role::factory()->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Pengguna Baru',
            'username' => 'pengguna_baru_lang',
            'password' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertCreated();
        $this->assertSame('en', User::where('username', 'pengguna_baru_lang')->first()->language);
    }
}
