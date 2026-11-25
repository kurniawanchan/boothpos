<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 094 / FR-005 — penanda peran "Boleh memakai aplikasi manajemen" (`roles.manager_app_enabled`). Owner/admin
 * mengaturnya di formulir Peran web; bawaannya mati untuk peran baru, menyala untuk Owner/Admin dan Outlet Manager.
 */
class RoleManagerAppFlagTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_system_owner_and_admin_roles_are_enabled_by_default(): void
    {
        User::factory()->create(['role' => 'owner']);
        User::factory()->create(['role' => 'admin']);

        $this->assertTrue((bool) Role::where('name', 'Owner')->value('manager_app_enabled'));
        $this->assertTrue((bool) Role::where('name', 'Admin')->value('manager_app_enabled'));
    }

    public function test_a_new_role_is_disabled_by_default(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/roles', ['name' => 'Supervisor Lantai', 'menu_keys' => ['floor']])
            ->assertCreated()
            ->assertJsonPath('manager_app_enabled', false);
    }

    public function test_owner_can_create_and_update_the_flag_and_it_is_listed(): void
    {
        $this->actingAsRole('owner');

        $id = $this->postJson('/api/v1/roles', ['name' => 'Manajer Outlet', 'menu_keys' => ['products'], 'manager_app_enabled' => true])
            ->assertCreated()
            ->assertJsonPath('manager_app_enabled', true)
            ->json('id');

        $this->putJson("/api/v1/roles/{$id}", ['manager_app_enabled' => false])
            ->assertOk()
            ->assertJsonPath('manager_app_enabled', false);

        $row = collect($this->getJson('/api/v1/roles?per_page=100')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertFalse($row['manager_app_enabled']);
    }

    public function test_the_flag_must_be_boolean(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/roles', ['name' => 'X', 'menu_keys' => ['pos'], 'manager_app_enabled' => 'ya'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manager_app_enabled');
    }

    public function test_a_role_without_the_roles_menu_cannot_change_the_flag(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create(['name' => 'Kasir Event', 'menu_keys' => ['pos']]);
        $this->actingAsRole('cashier');

        $this->putJson("/api/v1/roles/{$role->id}", ['manager_app_enabled' => true])->assertStatus(403);
        $this->assertFalse((bool) $role->fresh()->manager_app_enabled);
    }

    public function test_changing_the_flag_is_written_to_the_activity_log_with_before_and_after(): void
    {
        $this->actingAsRole('owner');
        $role = Role::factory()->create(['name' => 'Kepala Dapur', 'menu_keys' => ['kitchen']]);

        $this->putJson("/api/v1/roles/{$role->id}", ['manager_app_enabled' => true])->assertOk();

        $log = ActivityLog::query()->where('entity_type', 'Role')->where('entity_id', $role->id)
            ->where('action', 'role_manager_app_changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertFalse((bool) $log->old_values['manager_app_enabled']);
        $this->assertTrue((bool) $log->new_values['manager_app_enabled']);
    }
}
