<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 2 (T030) — penugasan pengguna ke outlet (PUT /users/{user}/outlets).
 *
 * Aturan (contracts/api-scope.md, D6): hanya administrator yang mengubah penugasan;
 * Owner/Admin selalu melihat semua outlet sehingga penugasannya ditolak (409);
 * outlet yang tidak ada atau nonaktif ditolak (422); setiap perubahan dicatat
 * di activity_logs dalam transaksi yang sama.
 */
class UserOutletAssignmentTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    public function test_an_owner_replaces_a_users_outlet_assignments(): void
    {
        $outlets = $this->makeOutlets(3);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0]]);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [$outlets[1]->id, $outlets[2]->id]])
            ->assertOk()
            ->assertJsonPath('outlet_ids', [$outlets[1]->id, $outlets[2]->id]);

        $this->assertEquals(
            [$outlets[1]->id, $outlets[2]->id],
            DB::table('outlet_user')->where('user_id', $cashier->id)->orderBy('outlet_id')->pluck('outlet_id')->all(),
        );
    }

    public function test_the_user_detail_and_list_expose_outlet_ids_and_the_all_outlets_flag(): void
    {
        $outlets = $this->makeOutlets(2);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[1]]);

        $this->getJson("/api/v1/users/{$cashier->id}")
            ->assertOk()
            ->assertJsonPath('outlet_ids', [$outlets[1]->id])
            ->assertJsonPath('all_outlets', false);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonFragment(['outlet_ids' => [$outlets[1]->id]]);
    }

    public function test_a_cashier_cannot_change_assignments(): void
    {
        $outlets = $this->makeOutlets(2);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $other = User::factory()->create(['role' => 'cashier']);

        $this->putJson("/api/v1/users/{$other->id}/outlets", ['outlet_ids' => [$outlets[0]->id]])
            ->assertForbidden();
    }

    public function test_an_owner_or_admin_account_cannot_be_assigned_because_it_sees_every_outlet(): void
    {
        $outlets = $this->makeOutlets(2);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->putJson("/api/v1/users/{$admin->id}/outlets", ['outlet_ids' => [$outlets[0]->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'outlet_all_by_role');
    }

    public function test_an_unknown_or_inactive_outlet_is_refused_with_422(): void
    {
        $outlets = $this->makeOutlets(2);
        $outlets[1]->update(['is_active' => false]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $cashier = User::factory()->unassigned()->create(['role' => 'cashier']);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('outlet_ids.0');

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [$outlets[1]->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('outlet_ids.0');

        $this->assertSame(0, DB::table('outlet_user')->where('user_id', $cashier->id)->count());
    }

    public function test_an_empty_list_means_intentionally_unassigned(): void
    {
        $outlets = $this->makeOutlets(1);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0]]);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => []])
            ->assertOk()
            ->assertJsonPath('outlet_ids', []);
    }

    public function test_the_change_is_written_to_the_activity_log_in_the_same_transaction(): void
    {
        $outlets = $this->makeOutlets(2);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[0]]);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [$outlets[1]->id]])->assertOk();

        $log = ActivityLog::where('action', 'user_outlets_updated')->where('entity_id', $cashier->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertEquals([$outlets[0]->id], $log->old_values['outlet_ids']);
        $this->assertEquals([$outlets[1]->id], $log->new_values['outlet_ids']);
    }

    /** Pengelola: menu `users` tanpa all_outlets — hanya melihat outlet yang ditugaskan kepadanya. */
    private function scopedManager(array $outlets): User
    {
        $role = \App\Models\Role::create([
            'name' => 'Pengelola Uji '.uniqid(),
            'menu_keys' => ['dashboard', 'users', 'outlets'],
            'all_outlets' => false,
        ]);
        $manager = User::factory()->create(['role_id' => $role->id]);
        $this->assignUser($manager, $outlets);

        return $manager;
    }

    public function test_nobody_can_assign_outlets_to_themselves(): void
    {
        $outlets = $this->makeOutlets(2);
        $manager = $this->scopedManager([$outlets[0], $outlets[1]]);
        $this->actingAs($manager, 'sanctum');

        $this->putJson("/api/v1/users/{$manager->id}/outlets", ['outlet_ids' => [$outlets[0]->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'outlet_forbidden');
    }

    public function test_a_manager_cannot_grant_an_outlet_they_cannot_see(): void
    {
        $outlets = $this->makeOutlets(2);
        $manager = $this->scopedManager([$outlets[0]]);
        $this->actingAs($manager, 'sanctum');
        $cashier = User::factory()->unassigned()->create(['role' => 'cashier']);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [$outlets[1]->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'outlet_forbidden');

        $this->assertSame(0, DB::table('outlet_user')->where('user_id', $cashier->id)->count());
    }

    public function test_a_manager_cannot_remove_an_outlet_they_cannot_see_from_another_user(): void
    {
        $outlets = $this->makeOutlets(2);
        $manager = $this->scopedManager([$outlets[0]]);
        $this->actingAs($manager, 'sanctum');
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlets[1]]);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [$outlets[0]->id]])
            ->assertForbidden()
            ->assertJsonPath('code', 'outlet_forbidden');

        $this->assertEquals(
            [$outlets[1]->id],
            DB::table('outlet_user')->where('user_id', $cashier->id)->pluck('outlet_id')->all(),
        );
    }

    public function test_a_manager_can_assign_within_their_own_outlets(): void
    {
        $outlets = $this->makeOutlets(2);
        $manager = $this->scopedManager([$outlets[0], $outlets[1]]);
        $this->actingAs($manager, 'sanctum');
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->putJson("/api/v1/users/{$cashier->id}/outlets", ['outlet_ids' => [$outlets[1]->id]])
            ->assertOk()
            ->assertJsonPath('outlet_ids', [$outlets[1]->id]);
    }

    public function test_a_new_non_admin_user_is_assigned_to_the_only_active_outlet(): void
    {
        $outlet = $this->makeOutlets(1)[0];
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $roleId = User::factory()->create(['role' => 'cashier'])->role_id;

        $response = $this->postJson('/api/v1/users', ['name' => 'Kasir Baru', 'username' => 'kasirbaru', 'password' => 'password123', 'role_id' => $roleId, 'is_active' => true])
            ->assertCreated();

        $response->assertJsonPath('outlet_ids', [$outlet->id]);
    }

    public function test_a_new_non_admin_user_is_not_auto_assigned_when_there_are_several_outlets(): void
    {
        $this->makeOutlets(2);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $roleId = User::factory()->create(['role' => 'cashier'])->role_id;

        $this->postJson('/api/v1/users', ['name' => 'Kasir Baru', 'username' => 'kasirbaru', 'password' => 'password123', 'role_id' => $roleId, 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('outlet_ids', []);
    }

    // ---- all_outlets op peran (Stage 2 leftover) ---------------------------------------------------

    public function test_a_user_with_full_scope_can_toggle_all_outlets_on_a_role_and_it_is_logged(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $role = \App\Models\Role::create(['name' => 'Kasir Cakupan', 'menu_keys' => ['pos'], 'all_outlets' => false]);

        $this->putJson("/api/v1/roles/{$role->id}", ['all_outlets' => true])->assertOk()->assertJsonPath('all_outlets', true);

        $this->assertTrue((bool) $role->fresh()->all_outlets);
        $this->assertDatabaseHas('activity_logs', ['action' => 'role_outlet_scope_changed', 'entity_id' => $role->id]);
    }

    public function test_a_scoped_manager_cannot_widen_a_role_to_all_outlets(): void
    {
        $outlets = $this->makeOutlets(2);
        $manager = $this->scopedManager([$outlets[0]]);
        $manager->role->update(['menu_keys' => ['dashboard', 'users', 'roles', 'outlets']]);
        $role = \App\Models\Role::create(['name' => 'Kasir Cakupan 2', 'menu_keys' => ['pos'], 'all_outlets' => false]);
        $this->actingAs($manager, 'sanctum');

        $this->putJson("/api/v1/roles/{$role->id}", ['all_outlets' => true])->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');
        $this->postJson('/api/v1/roles', ['name' => 'Peran Baru Lebar', 'menu_keys' => ['pos'], 'all_outlets' => true])->assertForbidden();

        $this->assertFalse((bool) $role->fresh()->all_outlets);
    }

    public function test_the_owner_role_cannot_lose_all_outlets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $owner = \App\Models\Role::query()->whereRaw('LOWER(name) = ?', ['owner'])->first();
        $this->assertNotNull($owner);

        $this->putJson("/api/v1/roles/{$owner->id}", ['all_outlets' => false])->assertStatus(409)->assertJsonPath('code', 'outlet_all_by_role');
    }
}
