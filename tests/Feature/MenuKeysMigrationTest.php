<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\MenuKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 028 — tiga kunci menu baru dan migrasi data yang memberikannya ke peran
 * default (research.md Keputusan 8). Pola yang sama dengan
 * 2026_10_19_000011_add_invoices_menu_key_to_default_roles.
 */
class MenuKeysMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_KEYS = ['floor', 'kitchen', 'dining_setup'];

    private function keysOf(string $roleName): array
    {
        $role = DB::table('roles')->where('name', $roleName)->first();

        return json_decode($role->menu_keys, true) ?? [];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_31_000008_add_restaurant_menu_keys_to_default_roles.php');
    }

    public function test_the_registry_knows_the_three_new_keys(): void
    {
        foreach (self::NEW_KEYS as $key) {
            $this->assertTrue(MenuKeys::isValid($key), "$key belum terdaftar di MenuKeys::ALL");
        }
    }

    public function test_default_roles_receive_the_agreed_keys(): void
    {
        $this->assertEmpty(array_diff(self::NEW_KEYS, $this->keysOf('Owner')));
        $this->assertEmpty(array_diff(self::NEW_KEYS, $this->keysOf('Admin')));

        $cashier = $this->keysOf('Kasir');
        $this->assertContains('floor', $cashier);
        $this->assertContains('kitchen', $cashier);
        $this->assertNotContains('dining_setup', $cashier);

        $inventory = $this->keysOf('Inventory');
        $this->assertContains('dining_setup', $inventory);
        $this->assertNotContains('floor', $inventory);
        $this->assertNotContains('kitchen', $inventory);
    }

    public function test_the_migration_is_idempotent(): void
    {
        $before = [$this->keysOf('Owner'), $this->keysOf('Kasir'), $this->keysOf('Inventory')];

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($before, [$this->keysOf('Owner'), $this->keysOf('Kasir'), $this->keysOf('Inventory')]);
    }

    public function test_down_removes_only_the_new_keys(): void
    {
        $ownerBefore = $this->keysOf('Owner');

        $this->migration()->down();

        $owner = $this->keysOf('Owner');
        $this->assertEmpty(array_intersect(self::NEW_KEYS, $owner));
        $this->assertSame(
            array_values(array_diff($ownerBefore, self::NEW_KEYS)),
            array_values($owner),
        );
        $this->assertNotContains('floor', $this->keysOf('Kasir'));
        $this->assertNotContains('dining_setup', $this->keysOf('Inventory'));
    }

    public function test_a_custom_role_is_never_touched_by_the_migration(): void
    {
        $custom = Role::factory()->create(['name' => 'Pelayan', 'menu_keys' => ['dashboard', 'pos']]);

        $this->migration()->up();

        $this->assertSame(['dashboard', 'pos'], $custom->fresh()->menu_keys);
    }

    public function test_the_menu_keys_endpoint_lists_the_new_keys_for_the_role_editor(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $keys = collect($this->getJson('/api/v1/menu-keys')->assertOk()->json('data'))->pluck('key');

        foreach (self::NEW_KEYS as $key) {
            $this->assertTrue($keys->contains($key), "/menu-keys tidak memuat $key");
        }
    }
}
