<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 — guard FR-013 diperluas: toko harus selalu punya peran dengan AKSI PENUH pada users DAN roles. */
class RoleLockoutActionsTest extends TestCase
{
    use RefreshDatabase;

    /** Hanya SATU peran yang "mampu": semua peran lain dibatasi/diturunkan. */
    private function makeSingleCapableRole(): Role
    {
        $capable = Role::factory()->create(['name' => 'Pengelola', 'menu_keys' => ['users', 'roles'], 'menu_actions' => null]);
        Role::where('id', '!=', $capable->id)->each(fn (Role $r) => $r->forceFill(['menu_keys' => ['dashboard']])->save());
        $u = User::factory()->create();
        $u->update(['role_id' => $capable->id]);
        $this->actingAs($u->fresh(), 'sanctum');

        return $capable;
    }

    public function test_restricting_the_last_fully_capable_role_is_a_conflict(): void
    {
        $capable = $this->makeSingleCapableRole();

        $this->putJson("/api/v1/roles/{$capable->id}", ['menu_actions' => ['users' => ['create'], 'roles' => ['create', 'edit', 'delete']]])
            ->assertStatus(409);

        $this->assertNull($capable->fresh()->menu_actions);
    }

    public function test_restricting_it_is_fine_when_another_role_still_has_full_actions_on_both(): void
    {
        $capable = $this->makeSingleCapableRole();
        Role::factory()->create(['name' => 'Cadangan', 'menu_keys' => ['users', 'roles'], 'menu_actions' => null]);

        $this->putJson("/api/v1/roles/{$capable->id}", ['menu_actions' => ['users' => [], 'roles' => []]])->assertOk();
    }

    public function test_a_role_with_both_menus_but_restricted_actions_does_not_count_as_capable(): void
    {
        $capable = $this->makeSingleCapableRole();
        Role::factory()->create(['name' => 'Terbatas', 'menu_keys' => ['users', 'roles'], 'menu_actions' => ['users' => [], 'roles' => []]]);

        $this->putJson("/api/v1/roles/{$capable->id}", ['menu_keys' => ['dashboard']])->assertStatus(409);
    }

    public function test_deleting_the_last_fully_capable_role_is_a_conflict(): void
    {
        $capable = $this->makeSingleCapableRole();
        Role::factory()->create(['name' => 'Terbatas', 'menu_keys' => ['users', 'roles'], 'menu_actions' => ['users' => [], 'roles' => []]]);
        $capable->users()->update(['is_active' => false]);

        $this->deleteJson("/api/v1/roles/{$capable->id}")->assertStatus(409);
    }
}
