<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 057 (US4) — upgrade tak terlihat: peran yang sudah ada (menu_actions NULL) tetap punya SEMUA aksi pada tiap menu
 * yang dimilikinya; peran dibuat dengan format lama (hanya menu) juga penuh.
 */
class RoleActionsBackCompatTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_role_keeps_full_access_on_each_menu_it_holds(): void
    {
        foreach (Role::all() as $role) {
            $this->assertNull($role->menu_actions, "{$role->name}: harus NULL (legacy) setelah migrasi");
            foreach ($role->menu_keys as $menu) {
                foreach (MenuActions::withView($menu) as $action) {
                    $this->assertTrue($role->allows($menu, $action), "{$role->name} kehilangan {$menu}.{$action}");
                }
            }
        }
        $this->assertGreaterThanOrEqual(4, Role::count(), 'Owner, Admin, Kasir, Inventory bawaan');
    }

    public function test_a_role_created_in_the_old_menu_only_format_is_full_access(): void
    {
        $owner = User::factory()->create();
        $owner->update(['role_id' => Role::where('name', 'Owner')->first()->id]);
        $this->actingAs($owner->fresh(), 'sanctum');

        $id = $this->postJson('/api/v1/roles', ['name' => 'Legacy', 'menu_keys' => ['sales', 'preorders']])->assertCreated()->json('id');

        $role = Role::find($id);
        $this->assertNull($role->menu_actions);
        $this->assertTrue($role->allows('sales', 'void'));
        $this->assertTrue($role->allows('preorders', 'delete'));
    }

    public function test_the_cashier_role_can_still_do_what_it_could_before(): void
    {
        $cashier = Role::where('name', 'Kasir')->firstOrFail();

        foreach (['pos' => ['view', 'create', 'draft'], 'sales' => ['view', 'verify', 'payment', 'export', 'receipts'], 'preorders' => ['view', 'create', 'payment']] as $menu => $actions) {
            foreach ($actions as $a) {
                $this->assertTrue($cashier->allows($menu, $a), "Kasir kehilangan {$menu}.{$a}");
            }
        }
    }
}
