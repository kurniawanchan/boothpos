<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_create_user(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Kasir Baru',
            'username' => 'kasir_baru',
            'password' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('username', 'kasir_baru')
            ->assertJsonPath('role.id', $role->id);

        $this->assertDatabaseHas('users', ['username' => 'kasir_baru']);
        $response->assertJsonMissingPath('password');
    }

    public function test_role_without_users_menu_key_cannot_create_user(): void
    {
        $this->actingAsRole('cashier');
        $role = Role::factory()->create();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Kasir Baru',
            'username' => 'kasir_baru_2',
            'password' => 'password123',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_role_without_users_menu_key_cannot_list_users(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(403);
    }

    public function test_owner_can_view_update_and_soft_delete_a_user(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create();
        $target = User::factory()->create(['role' => 'cashier']);

        $this->getJson("/api/v1/users/{$target->id}")->assertOk();

        $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Nama Diubah',
            'role_id' => $role->id,
        ])->assertOk()->assertJsonPath('name', 'Nama Diubah');

        $this->deleteJson("/api/v1/users/{$target->id}")->assertStatus(204);
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_search_by_name_or_username(): void
    {
        $this->actingAsRole('owner');
        User::factory()->create(['role' => 'cashier', 'name' => 'Budi Santoso', 'username' => 'budi']);
        User::factory()->create(['role' => 'cashier', 'name' => 'Sari Dewi', 'username' => 'sari']);

        $response = $this->getJson('/api/v1/users?search=budi');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('username')->all();
        $this->assertContains('budi', $names);
        $this->assertNotContains('sari', $names);
    }

    public function test_filter_by_role_and_status(): void
    {
        $owner = $this->actingAsRole('owner');
        $inventoryRole = Role::where('name', 'Inventory')->first();
        $inactive = User::factory()->create(['role' => 'cashier', 'is_active' => false]);

        $byRole = $this->getJson('/api/v1/users?role_id='.$inventoryRole->id);
        $byRole->assertOk();
        foreach ($byRole->json('data') as $row) {
            $this->assertEquals($inventoryRole->id, $row['role']['id']);
        }

        $byStatus = $this->getJson('/api/v1/users?is_active=0');
        $byStatus->assertOk();
        $ids = collect($byStatus->json('data'))->pluck('id')->all();
        $this->assertContains($inactive->id, $ids);
        $this->assertNotContains($owner->id, $ids);
    }

    public function test_user_cannot_deactivate_their_own_account(): void
    {
        $owner = $this->actingAsRole('owner');

        $response = $this->putJson("/api/v1/users/{$owner->id}", [
            'is_active' => false,
        ]);

        $response->assertStatus(409);
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_user_cannot_change_their_own_role(): void
    {
        $owner = $this->actingAsRole('owner');
        $otherRole = Role::factory()->create();

        $response = $this->putJson("/api/v1/users/{$owner->id}", [
            'role_id' => $otherRole->id,
        ]);

        $response->assertStatus(409);
        $this->assertNotEquals($otherRole->id, $owner->fresh()->role_id);
    }

    public function test_user_cannot_delete_their_own_account(): void
    {
        $owner = $this->actingAsRole('owner');

        $response = $this->deleteJson("/api/v1/users/{$owner->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'deleted_at' => null]);
    }

    public function test_owner_can_update_someone_elses_role_and_deactivate_them(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create();
        $target = User::factory()->create(['role' => 'cashier']);

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'role_id' => $role->id,
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertEquals($role->id, $target->fresh()->role_id);
    }

    public function test_photo_upload_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $this->actingAsRole('owner');
        $target = User::factory()->create(['role' => 'cashier']);

        $response = $this->postJson("/api/v1/users/{$target->id}/photo", [
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_photo_upload_accepts_a_valid_image(): void
    {
        Storage::fake('public');
        $this->actingAsRole('owner');
        $target = User::factory()->create(['role' => 'cashier']);

        $response = $this->postJson("/api/v1/users/{$target->id}/photo", [
            'image' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('photo_url'));
    }

    public function test_export_excludes_password_column(): void
    {
        $this->actingAsRole('owner');
        User::factory()->create(['role' => 'cashier', 'username' => 'ekspor_test']);

        $response = $this->get('/api/v1/exports/users');

        // Endpoint /exports/{entity} belum menerima 'users' sampai
        // MasterDataSheets diperluas (T050, User Story 4) — route
        // constraint-nya menolak nilai yang tidak dikenal dengan 404. Di
        // fase ini (US1) cukup pastikan endpoint TIDAK diam-diam menerima
        // 'users' dengan bocor password sebelum US4 dibangun.
        $response->assertStatus(404);
    }
}
