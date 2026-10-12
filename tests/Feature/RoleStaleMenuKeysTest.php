<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 046 (T007) — kunci menu `events`/`preorders` yang masih tersimpan pada peran dicabut oleh migrasi
 * data dan tidak pernah membuat pengguna terkunci (FR-015).
 */
class RoleStaleMenuKeysTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_12_10_000002_remove_event_and_preorder_menu_keys.php');
    }

    public function test_the_data_migration_strips_both_keys_and_keeps_the_rest(): void
    {
        $role = Role::factory()->create(['name' => 'Kasir Lama', 'menu_keys' => ['dashboard', 'events', 'pos', 'preorders']]);

        $this->migration()->up();

        $this->assertSame(['dashboard', 'pos'], json_decode(DB::table('roles')->where('id', $role->id)->value('menu_keys'), true));
    }

    public function test_the_data_migration_is_idempotent(): void
    {
        $role = Role::factory()->create(['name' => 'Kasir Lama', 'menu_keys' => ['dashboard', 'events']]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(['dashboard'], json_decode(DB::table('roles')->where('id', $role->id)->value('menu_keys'), true));
    }
}
