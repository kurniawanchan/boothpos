<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 045 Stage 2 (T032, D17) — lantai restoran per outlet.
 *
 * Satu outlet yang berlaku → bentuk lama (`areas`, `takeaway_tabs`); beberapa → `outlets[]`; header
 * X-Outlet-Id mempersempit; nama meja boleh sama di outlet berbeda; tab tidak bisa dipindah ke meja outlet lain.
 */
class FloorOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase, RestaurantFixtures;

    public function test_a_single_outlet_keeps_the_original_floor_shape(): void
    {
        $outlet = $this->makeOutlets(1)[0];
        $this->tableAt($outlet, 'A1');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $floor = $this->getJson('/api/v1/floor')->assertOk()->json();

        $this->assertArrayHasKey('areas', $floor);
        $this->assertArrayNotHasKey('outlets', $floor);
    }

    public function test_several_outlets_are_grouped_and_the_header_narrows_to_one(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->tableAt($a, '1');
        $this->tableAt($b, '1'); // nama sama di outlet lain: boleh
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $all = $this->getJson('/api/v1/floor')->assertOk()->json();
        $this->assertSame([$a->id, $b->id], array_column($all['outlets'], 'id'));
        $this->assertCount(1, $all['outlets'][1]['areas'][0]['tables']);

        $one = $this->withHeader('X-Outlet-Id', (string) $b->id)->getJson('/api/v1/floor')->assertOk()->json();
        $this->assertArrayNotHasKey('outlets', $one);
        $this->assertCount(1, $one['areas'][0]['tables']);
    }

    public function test_a_tab_cannot_be_moved_to_a_table_of_another_outlet(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $shift = $this->shiftAt($a, $cashier);
        $tab = $this->makeOpenOrder($shift, $cashier, ['service_type' => 'takeaway']);
        $foreign = $this->tableAt($b, 'B9');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->postJson("/api/v1/tabs/{$tab->id}/move", ['table_id' => $foreign->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'outlet_mismatch');

        $own = $this->tableAt($a, 'A9');
        $this->postJson("/api/v1/tabs/{$tab->id}/move", ['table_id' => $own->id])->assertOk();
    }

    public function test_a_table_and_a_station_take_the_chosen_permitted_outlet(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->postJson('/api/v1/dining-tables', ['name' => '1', 'outlet_id' => $a->id])->assertCreated()->assertJsonPath('outlet_id', $a->id);
        $this->postJson('/api/v1/dining-tables', ['name' => '1', 'outlet_id' => $b->id])->assertCreated()->assertJsonPath('outlet_id', $b->id);
        $this->postJson('/api/v1/dining-tables', ['name' => '1', 'outlet_id' => $a->id])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/stations', ['name' => 'Dapur', 'outlet_id' => $a->id])->assertCreated();
        $this->postJson('/api/v1/stations', ['name' => 'Dapur', 'outlet_id' => $b->id])->assertCreated();
        $this->assertSame(2, \App\Models\Station::withoutGlobalScopes()->where('name', 'Dapur')->count());
    }

    public function test_an_outlet_outside_the_permitted_set_is_refused_for_new_tables_and_stations(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $manager = User::factory()->unassigned()->create(['role' => 'owner']); // owner: all outlets → use a scoped role below
        $role = \App\Models\Role::create(['name' => 'Pengelola Lantai', 'menu_keys' => ['floor', 'dashboard', 'dining_setup', 'settings'], 'all_outlets' => false]);
        $scoped = User::factory()->create(['role_id' => $role->id]);
        $this->assignUser($scoped, [$a]);
        $this->actingAs($scoped, 'sanctum');

        $this->postJson('/api/v1/dining-tables', ['name' => 'X', 'outlet_id' => $b->id])->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');
        $this->postJson('/api/v1/stations', ['name' => 'X', 'outlet_id' => $b->id])->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');
    }
}
