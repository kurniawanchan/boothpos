<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_owner_can_list_roles_with_user_count(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create(['name' => 'Kasir Event A', 'menu_keys' => ['pos']]);
        User::factory()->count(2)->create(['role_id' => $role->id, 'is_active' => true]);
        // Pengguna nonaktif TIDAK boleh ikut terhitung di user_count.
        User::factory()->create(['role_id' => $role->id, 'is_active' => false]);

        $response = $this->getJson('/api/v1/roles');

        $response->assertOk()->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $row = collect($response->json('data'))->firstWhere('name', 'Kasir Event A');
        $this->assertSame(2, $row['user_count']);
    }

    public function test_cashier_cannot_access_roles(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->postJson('/api/v1/roles', ['name' => 'X', 'menu_keys' => ['pos']])->assertStatus(403);
    }

    public function test_owner_can_create_role_with_valid_menu_keys(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Kasir Event A',
            'menu_keys' => ['pos', 'session'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Kasir Event A')
            ->assertJsonPath('menu_keys', ['pos', 'session'])
            ->assertJsonPath('user_count', 0);
    }

    public function test_create_role_rejects_unknown_menu_key(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Peran Aneh',
            'menu_keys' => ['pos', 'nonexistent-menu'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('menu_keys.1');
    }

    public function test_role_name_must_be_unique(): void
    {
        $this->actingAsRole('owner');
        Role::factory()->create(['name' => 'Kasir Event A']);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Kasir Event A',
            'menu_keys' => ['pos'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_owner_can_update_role_name_and_menu_keys(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create(['name' => 'Peran Lama', 'menu_keys' => ['pos']]);

        $response = $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Peran Baru',
            'menu_keys' => ['pos', 'session', 'stock'],
        ]);

        $response->assertOk()->assertJsonPath('name', 'Peran Baru')->assertJsonPath('menu_keys', ['pos', 'session', 'stock']);
    }

    public function test_delete_rejected_when_role_still_used_by_active_user(): void
    {
        $owner = $this->actingAsRole('owner');
        $role = Role::factory()->create(['name' => 'Kasir Event A', 'menu_keys' => ['pos']]);
        User::factory()->count(3)->create(['role_id' => $role->id, 'is_active' => true]);

        $response = $this->deleteJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(409)->assertJsonPath('message', 'Tidak bisa dihapus — masih dipakai oleh 3 pengguna.');
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'deleted_at' => null]);
    }

    public function test_delete_allowed_when_role_only_used_by_inactive_users(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create(['name' => 'Kasir Event A', 'menu_keys' => ['pos']]);
        User::factory()->create(['role_id' => $role->id, 'is_active' => false]);

        $this->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(204);
        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    /**
     * Membentuk dunia "hanya $soleCapableRoleId yang mampu mengelola
     * users+roles": mencabut KEDUA kunci reserved dari seluruh peran lain,
     * KECUALI peran aktor yang sedang login ($actorRoleId) — dari situ
     * hanya kunci 'roles' yang dipertahankan (supaya aktor tetap boleh
     * memanggil endpoint /roles sama sekali, tanpa ikut terhitung sebagai
     * peran yang mampu mengelola users+roles, karena 'users'-nya dicabut).
     */
    private function leaveOnlyOneRoleCapableOfManagingAccess(int $soleCapableRoleId, int $actorRoleId): void
    {
        Role::whereNotIn('id', [$soleCapableRoleId, $actorRoleId])->get()->each(
            fn (Role $r) => $r->update(['menu_keys' => array_values(array_diff($r->menu_keys, MenuKeys::RESERVED))])
        );

        if ($actorRoleId !== $soleCapableRoleId) {
            $actorRole = Role::find($actorRoleId);
            $actorRole->update(['menu_keys' => array_values(array_diff($actorRole->menu_keys, ['users']))]);
        }
    }

    public function test_delete_rejected_when_it_is_the_last_role_capable_of_managing_users_and_roles(): void
    {
        $owner = $this->actingAsRole('owner');

        // Peran terpisah, TIDAK dipakai pengguna manapun (jadi FR-014 tidak
        // ikut ter-trigger) — satu-satunya peran lain yang mencakup
        // users+roles selain peran bawaan Owner/Admin.
        $soleOtherCapableRole = Role::factory()->withUserManagement()->create();
        $this->leaveOnlyOneRoleCapableOfManagingAccess($soleOtherCapableRole->id, $owner->role_id);

        $response = $this->deleteJson("/api/v1/roles/{$soleOtherCapableRole->id}");

        $response->assertStatus(409);
        $this->assertStringContainsString('peran terakhir', $response->json('message'));
        $this->assertDatabaseHas('roles', ['id' => $soleOtherCapableRole->id, 'deleted_at' => null]);
    }

    public function test_update_rejected_when_it_would_remove_the_last_management_capable_role(): void
    {
        $owner = $this->actingAsRole('owner');

        $soleOtherCapableRole = Role::factory()->withUserManagement()->create();
        $this->leaveOnlyOneRoleCapableOfManagingAccess($soleOtherCapableRole->id, $owner->role_id);

        // Kasus edit yang harus ditolak terpisah dari kasus delete di atas
        // (spec T029 minta keduanya diuji sebagai kasus yang berbeda):
        // mengubah menu_keys peran ini supaya tidak lagi mencakup 'roles'.
        $response = $this->putJson("/api/v1/roles/{$soleOtherCapableRole->id}", [
            'menu_keys' => ['dashboard', 'pos', 'users'],
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('peran terakhir', $response->json('message'));
        $this->assertContains('roles', $soleOtherCapableRole->fresh()->menu_keys);
    }

    public function test_update_allowed_when_another_role_still_manages_access(): void
    {
        $this->actingAsRole('owner');
        // 'admin' seed role juga full-access (lihat migrasi seed), jadi
        // masih ada peran lain yang mampu — perubahan ini harus diizinkan.
        $role = Role::factory()->withUserManagement()->create();

        $response = $this->putJson("/api/v1/roles/{$role->id}", [
            'menu_keys' => ['dashboard', 'pos'],
        ]);

        $response->assertOk();
    }

    public function test_menu_keys_endpoint_returns_full_registry(): void
    {
        $this->actingAsRole('owner');

        $response = $this->getJson('/api/v1/menu-keys');

        $response->assertOk();
        $this->assertCount(count(MenuKeys::keys()), $response->json('data'));
        $this->assertSame(['key' => 'roles', 'label' => 'Peran'], collect($response->json('data'))->firstWhere('key', 'roles'));
    }
}
