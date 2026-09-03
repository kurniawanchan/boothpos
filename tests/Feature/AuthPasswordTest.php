<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 005-ux-enhancements-dashboard (US3) — swa-layanan ganti password.
 * Sengaja TIDAK digerbang UserPolicy (lihat AuthController::updatePassword()),
 * sama seperti pola LanguagePreferenceTest untuk /auth/language.
 */
class AuthPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_changes_with_correct_current_password_and_session_stays_valid(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'password' => Hash::make('old-password-123')]);
        $token = $user->createToken('pos-session')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'old-password-123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertOk();

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));

        // Token lama masih berlaku — ganti password TIDAK mencabut sesi
        // yang sedang dipakai (beda dari reset password oleh admin).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_incorrect_current_password_is_rejected_and_password_unchanged(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'password' => Hash::make('old-password-123')]);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_new_password_failing_confirmation_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'password' => Hash::make('old-password-123')]);
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'mismatch',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // FR-003/FR-004-style guarantee, sama seperti /auth/language: kasir
    // tanpa akses menu 'users' tetap harus bisa ganti password sendiri.
    public function test_a_user_without_users_menu_access_can_still_change_own_password(): void
    {
        $kasir = User::factory()->create(['role' => 'cashier', 'password' => Hash::make('old-password-123')]);
        $this->assertFalse($kasir->canAccessMenu('users'));
        $this->actingAs($kasir, 'sanctum');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();
    }
}
