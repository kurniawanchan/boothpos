<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T015) — izin tingkat-aksi di samping menu_keys.
 */
class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $name, ?array $permissions = null): User
    {
        // Peran 'Owner'/'Admin'/... sudah dibuat pabrik pengguna; perbarui bila ada, buat bila belum.
        $role = Role::updateOrCreate(['name' => $name], ['menu_keys' => ['dashboard', 'products'], 'permissions' => $permissions]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_system_roles_get_their_defaults_when_permissions_are_not_set(): void
    {
        $this->assertTrue($this->userWithRole('Owner')->hasPermission(PermissionCatalog::PRICE_APPROVE));
        $this->assertTrue($this->userWithRole('Admin')->hasPermission(PermissionCatalog::RELEASE_PUBLISH));
        $inventory = $this->userWithRole('Inventory');
        $this->assertTrue($inventory->hasPermission(PermissionCatalog::ITEM_86));
        $this->assertFalse($inventory->hasPermission(PermissionCatalog::PRICE_APPROVE));
        $this->assertFalse($this->userWithRole('Kasir Pagi')->hasPermission(PermissionCatalog::ITEM_86));
    }

    public function test_stored_permissions_are_honoured_exactly_even_when_empty(): void
    {
        $this->assertFalse($this->userWithRole('Owner', [])->hasPermission(PermissionCatalog::PRICE_APPROVE));
        $this->assertTrue($this->userWithRole('Restaurant Manager', [PermissionCatalog::PRICE_APPROVE])->hasPermission(PermissionCatalog::PRICE_APPROVE));
        $this->assertFalse($this->userWithRole('Restaurant Manager', [PermissionCatalog::PRICE_APPROVE])->hasPermission(PermissionCatalog::RELEASE_PUBLISH));
    }

    public function test_the_roles_api_accepts_and_returns_effective_permissions_and_rejects_unknown_keys(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $id = $this->postJson('/api/v1/roles', ['name' => 'Manager Resto', 'menu_keys' => ['dashboard', 'products'], 'permissions' => [PermissionCatalog::PRICE_APPROVE]])
            ->assertCreated()->assertJsonPath('permissions', [PermissionCatalog::PRICE_APPROVE])->json('id');

        $this->putJson("/api/v1/roles/{$id}", ['permissions' => [PermissionCatalog::ITEM_86, PermissionCatalog::RECIPE_EDIT]])
            ->assertOk()->assertJsonPath('permissions', [PermissionCatalog::ITEM_86, PermissionCatalog::RECIPE_EDIT]);

        $this->putJson("/api/v1/roles/{$id}", ['permissions' => ['menu.everything']])->assertStatus(422);

        $this->getJson('/api/v1/permission-catalog')->assertOk()->assertJsonCount(count(PermissionCatalog::ALL), 'data');
    }

    public function test_a_default_role_shows_its_effective_permissions_in_the_roles_api(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $inventoryId = Role::where('name', 'Inventory')->value('id') ?? Role::factory()->create(['name' => 'Inventory', 'menu_keys' => ['products']])->id;

        $this->getJson("/api/v1/roles/{$inventoryId}")->assertOk()
            // 045 Stage 6: inventory juga mengirim dan menerima transfer stok (bukan membatalkan).
            ->assertJsonPath('permissions', [PermissionCatalog::ITEM_MANAGE, PermissionCatalog::ITEM_86, PermissionCatalog::RECIPE_EDIT, PermissionCatalog::TRANSFER_DISPATCH, PermissionCatalog::TRANSFER_RECEIVE]);
    }
}
