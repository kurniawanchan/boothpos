<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 057 — Role::allows / User::canDo: NULL = akses penuh (lama), [] = hanya lihat, Owner selalu penuh. */
class RoleCanDoTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $roleAttrs): User
    {
        $role = Role::factory()->create($roleAttrs);
        $user = User::factory()->create();
        $user->update(['role_id' => $role->id]);

        return $user->fresh();
    }

    public function test_null_menu_actions_means_full_access_on_every_held_menu(): void
    {
        $u = $this->userWith(['menu_keys' => ['sales', 'preorders'], 'menu_actions' => null]);

        $this->assertTrue($u->canDo('sales', 'void'));
        $this->assertTrue($u->canDo('preorders', 'create'));
        $this->assertTrue($u->canDo('sales', 'view'));
    }

    public function test_an_empty_entry_is_view_only_and_a_named_entry_adds_exactly_that_action(): void
    {
        $u = $this->userWith(['menu_keys' => ['sales', 'preorders'], 'menu_actions' => ['sales' => [], 'preorders' => ['status']]]);

        $this->assertTrue($u->canDo('sales', 'view'));
        $this->assertFalse($u->canDo('sales', 'void'));
        $this->assertTrue($u->canDo('preorders', 'view'));
        $this->assertTrue($u->canDo('preorders', 'status'));
        $this->assertFalse($u->canDo('preorders', 'delete'));
    }

    public function test_a_menu_the_role_does_not_hold_grants_nothing_even_if_listed_in_actions(): void
    {
        $u = $this->userWith(['menu_keys' => ['pos'], 'menu_actions' => ['sales' => ['void']]]);

        $this->assertFalse($u->canDo('sales', 'view'));
        $this->assertFalse($u->canDo('sales', 'void'));
    }

    public function test_a_held_menu_without_an_entry_is_full_access(): void
    {
        $u = $this->userWith(['menu_keys' => ['sales', 'pos'], 'menu_actions' => ['sales' => []]]);

        $this->assertFalse($u->canDo('sales', 'void'));
        $this->assertTrue($u->canDo('pos', 'create'));   // pos tanpa entri ⇒ penuh (peran lama/impor)
    }

    public function test_unknown_stored_actions_are_ignored_never_granted(): void
    {
        $u = $this->userWith(['menu_keys' => ['sales'], 'menu_actions' => ['sales' => ['void', 'hack', 'delete']]]);

        $this->assertTrue($u->canDo('sales', 'void'));
        $this->assertFalse($u->canDo('sales', 'receipts'));
        $this->assertSame(['view', 'void'], $u->role->resolvedActions()['sales']);
    }

    public function test_the_owner_role_is_always_full_whatever_is_stored(): void
    {
        // Owner bawaan sudah ada dari migrasi; sengaja diisi `menu_actions` yang membatasi — tetap harus penuh.
        $owner = Role::where('name', 'Owner')->firstOrFail();
        $owner->update(['menu_keys' => ['sales'], 'menu_actions' => ['sales' => []]]);
        $u = User::factory()->create();
        $u->update(['role_id' => $owner->id]);
        $u = $u->fresh();

        $this->assertTrue($u->canDo('sales', 'void'));
        $this->assertSame(['view', 'void', 'payment', 'payment_method', 'verify', 'reference', 'export', 'receipts', 'approve'], $u->role->resolvedActions()['sales']);
    }

    public function test_a_user_without_a_role_can_do_nothing(): void
    {
        $u = User::factory()->create();
        $u->setRelation('role', null);

        $this->assertFalse($u->canDo('sales', 'view'));
    }
}
