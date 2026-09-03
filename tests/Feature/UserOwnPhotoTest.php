<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 005-ux-enhancements-dashboard (US3) — POST /auth/photo, swa-layanan.
 * Sengaja endpoint TERPISAH dari POST /users/{user}/photo (yang digerbang
 * UserPolicy::update() / canAccessMenu('users')) — lihat
 * AuthController::updatePhoto() dan research.md R6. Titik uji utamanya:
 * kasir yang GAGAL UserPolicy::update() tetap harus bisa ganti fotonya
 * sendiri lewat rute ini.
 */
class UserOwnPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_users_menu_access_can_change_own_photo(): void
    {
        Storage::fake('public');
        $kasir = User::factory()->create(['role' => 'cashier']);
        $this->assertFalse($kasir->canAccessMenu('users'));
        $this->actingAs($kasir, 'sanctum');

        $response = $this->postJson('/api/v1/auth/photo', [
            'image' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('photo_url'));
        $this->assertNotNull($kasir->fresh()->photo_path);
    }

    public function test_photo_upload_only_ever_touches_the_caller_own_row(): void
    {
        Storage::fake('public');
        $kasir = User::factory()->create(['role' => 'cashier']);
        $otherUser = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($kasir, 'sanctum');

        $this->postJson('/api/v1/auth/photo', [
            'image' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ])->assertOk();

        $this->assertNull($otherUser->fresh()->photo_path);
    }

    public function test_photo_upload_rejects_non_image_file_and_leaves_existing_photo_unchanged(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'owner', 'photo_path' => 'users/existing.jpg']);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/v1/auth/photo', [
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $this->assertSame('users/existing.jpg', $user->fresh()->photo_path);
    }
}
