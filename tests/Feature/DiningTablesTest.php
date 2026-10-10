<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US1 — manajemen meja (FR-001, FR-002, FR-038–FR-040).
 */
class DiningTablesTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $inventory;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        // Peran default hasil migrasi: Inventory punya `dining_setup`, Kasir tidak.
        $this->inventory = User::factory()->create(['role' => 'inventory']);
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->inventory, 'sanctum');
    }

    public function test_a_master_data_manager_can_create_a_table(): void
    {
        $this->postJson('/api/v1/dining-tables', ['name' => '7', 'area' => 'Indoor', 'seats' => 4])
            ->assertCreated()
            ->assertJsonPath('name', '7')
            ->assertJsonPath('area', 'Indoor')
            ->assertJsonPath('seats', 4)
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('has_open_tab', false);

        $this->assertDatabaseHas('dining_tables', ['name' => '7', 'area' => 'Indoor', 'data_mode' => 'live']);
    }

    public function test_area_is_optional_and_stored_as_empty_string(): void
    {
        $this->postJson('/api/v1/dining-tables', ['name' => 'Bar-1', 'area' => null, 'seats' => 1])
            ->assertCreated()->assertJsonPath('area', '');
    }

    public function test_required_fields_and_bounds_are_validated(): void
    {
        $this->postJson('/api/v1/dining-tables', [])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/v1/dining-tables', ['name' => 'X', 'seats' => 0])->assertStatus(422)->assertJsonValidationErrors(['seats']);
        $this->postJson('/api/v1/dining-tables', ['name' => str_repeat('a', 61)])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_a_duplicate_name_in_the_same_area_is_rejected_but_another_area_is_fine(): void
    {
        DiningTable::factory()->inArea('Indoor')->create(['name' => '1']);

        $this->postJson('/api/v1/dining-tables', ['name' => '1', 'area' => 'Indoor'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson('/api/v1/dining-tables', ['name' => '1', 'area' => 'Terrace'])->assertCreated();
        $this->assertSame(2, DiningTable::where('name', '1')->count());
    }

    public function test_the_same_name_may_exist_in_demo_and_live_mode(): void
    {
        ModeGate::runAs('demo', fn () => DiningTable::factory()->inArea('Indoor')->create(['name' => '1']));

        // Aturan unique pada FormRequest melewati Eloquent global scope — harus
        // dibatasi ke mode aktif secara eksplisit, atau nama DEMO memblokir LIVE.
        $this->postJson('/api/v1/dining-tables', ['name' => '1', 'area' => 'Indoor'])->assertCreated();
    }

    public function test_tables_of_the_other_data_mode_are_invisible(): void
    {
        ModeGate::runAs('demo', fn () => DiningTable::factory()->create(['name' => 'DEMO-ONLY']));
        DiningTable::factory()->create(['name' => 'LIVE-ONLY']);

        $names = collect($this->getJson('/api/v1/dining-tables')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('LIVE-ONLY'));
        $this->assertFalse($names->contains('DEMO-ONLY'));
    }

    public function test_a_table_can_be_edited_and_deactivated(): void
    {
        $table = DiningTable::factory()->create(['name' => '5', 'seats' => 2]);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['name' => '5A', 'seats' => 6, 'is_active' => false])
            ->assertOk()->assertJsonPath('name', '5A')->assertJsonPath('seats', 6)->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('activity_logs', ['action' => 'deactivated', 'entity_type' => 'DiningTable', 'entity_id' => $table->id]);
    }

    public function test_a_table_with_an_open_tab_cannot_be_deactivated(): void
    {
        $table = DiningTable::factory()->create();
        $session = $this->makeShift($this->cashier);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['is_active' => false])->assertStatus(409);

        $this->assertTrue($table->fresh()->is_active);
    }

    public function test_an_unused_table_can_be_deleted_and_the_deletion_is_logged(): void
    {
        $table = DiningTable::factory()->create(['name' => 'Z9']);

        $this->deleteJson("/api/v1/dining-tables/{$table->id}")->assertNoContent();

        $this->assertDatabaseMissing('dining_tables', ['id' => $table->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'entity_type' => 'DiningTable', 'entity_id' => $table->id]);
    }

    public function test_a_table_that_ever_had_an_order_cannot_be_deleted(): void
    {
        $table = DiningTable::factory()->create();
        $session = $this->makeShift($this->cashier);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'status' => 'completed']);

        $this->deleteJson("/api/v1/dining-tables/{$table->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('dining_tables', ['id' => $table->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'deleted', 'entity_type' => 'DiningTable', 'entity_id' => $table->id]);
    }

    public function test_the_list_flags_which_tables_currently_have_an_open_tab(): void
    {
        $busy = DiningTable::factory()->create(['name' => 'busy']);
        DiningTable::factory()->create(['name' => 'idle']);
        $session = $this->makeShift($this->cashier);
        $this->makeOpenOrder($session, $this->cashier, ['table_id' => $busy->id, 'service_type' => 'dine_in']);

        $rows = collect($this->getJson('/api/v1/dining-tables')->assertOk()->json('data'))->keyBy('name');

        $this->assertTrue($rows['busy']['has_open_tab']);
        $this->assertFalse($rows['idle']['has_open_tab']);
    }

    public function test_the_active_filter_hides_inactive_tables(): void
    {
        DiningTable::factory()->create(['name' => 'on']);
        DiningTable::factory()->inactive()->create(['name' => 'off']);

        $names = collect($this->getJson('/api/v1/dining-tables?active=1')->assertOk()->json('data'))->pluck('name');

        $this->assertSame(['on'], $names->all());
    }

    public function test_a_role_without_dining_setup_is_refused_everywhere(): void
    {
        $table = DiningTable::factory()->create();
        $this->actingAs($this->cashier, 'sanctum');

        $this->getJson('/api/v1/dining-tables')->assertForbidden();
        $this->postJson('/api/v1/dining-tables', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/dining-tables/{$table->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/dining-tables/{$table->id}")->assertForbidden();
    }

    public function test_guests_are_unauthenticated(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dining-tables')->assertUnauthorized();
    }
}
