<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 071 (US2) — PUT /dining-tables/{id} dengan `outlet_id` memindahkan meja: izin outlet tujuan, penolakan 409 bila ada tab
 * terbuka, keunikan nama di tujuan (422), log `moved`, riwayat order tak berubah, dan atomik.
 */
class DiningTableMoveTest extends TestCase
{
    use OutletFixtures, RefreshDatabase, RestaurantFixtures;

    private $a;

    private $b;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function table(string $name, $outlet, array $over = []): DiningTable
    {
        return DiningTable::factory()->create(['name' => $name, 'outlet_id' => $outlet->id] + $over);
    }

    public function test_a_table_moves_to_another_permitted_outlet_and_the_move_is_logged(): void
    {
        $table = $this->table('D1', $this->a);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id])
            ->assertOk()->assertJsonPath('outlet_id', $this->b->id);

        $this->assertSame($this->b->id, (int) DiningTable::withoutGlobalScopes()->find($table->id)->outlet_id);
        $log = ActivityLog::where('entity_type', 'DiningTable')->where('entity_id', $table->id)->where('action', 'moved')->firstOrFail();
        $this->assertSame($this->a->id, (int) $log->old_values['outlet_id']);
        $this->assertSame($this->b->id, (int) $log->new_values['outlet_id']);
        $this->assertStringContainsString($this->a->code, $log->description);
        $this->assertStringContainsString($this->b->code, $log->description);
        $this->assertNotNull($log->user_id);
    }

    public function test_the_moved_table_follows_the_outlet_lists(): void
    {
        $table = $this->table('D1', $this->a);
        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id])->assertOk();

        $atA = collect($this->getJson("/api/v1/dining-tables?outlet_id[]={$this->a->id}")->json('data'))->pluck('name')->all();
        $atB = collect($this->getJson("/api/v1/dining-tables?outlet_id[]={$this->b->id}")->json('data'))->pluck('name')->all();

        $this->assertSame([], $atA);
        $this->assertSame(['D1'], $atB);
    }

    public function test_a_table_with_an_open_tab_is_refused_with_the_reason_and_stays_put(): void
    {
        $table = $this->table('D1', $this->a);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->makeOpenOrder($this->makeShift($cashier), $cashier, ['table_id' => $table->id]);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id, 'name' => 'Baru'])
            ->assertStatus(409)->assertJsonPath('message', __('restaurant.table_move_open_tab'));

        $fresh = DiningTable::withoutGlobalScopes()->find($table->id);
        $this->assertSame($this->a->id, (int) $fresh->outlet_id);
        $this->assertSame('D1', $fresh->name, 'perubahan lain pada permintaan yang gagal tidak tersimpan');
        $this->assertSame(0, ActivityLog::where('action', 'moved')->count());
    }

    public function test_finished_orders_do_not_block_and_keep_their_own_outlet(): void
    {
        $table = $this->table('D1', $this->a);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $order = $this->makeOpenOrder($this->makeShift($cashier), $cashier, ['table_id' => $table->id, 'status' => Order::STATUS_COMPLETED]);
        $before = (int) Order::withoutGlobalScopes()->find($order->id)->outlet_id;

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id])->assertOk();

        $this->assertSame($before, (int) Order::withoutGlobalScopes()->find($order->id)->outlet_id, 'riwayat order tidak dipindah');
    }

    public function test_an_outlet_the_user_may_not_see_is_forbidden(): void
    {
        $table = $this->table('D1', $this->a);
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$this->a]);
        $this->actingAs($user, 'sanctum');

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id])
            ->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');

        $this->assertSame($this->a->id, (int) DiningTable::withoutGlobalScopes()->find($table->id)->outlet_id);
    }

    public function test_an_unknown_outlet_is_a_validation_error(): void
    {
        $table = $this->table('D1', $this->a);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('outlet_id');
    }

    public function test_a_name_already_used_at_the_target_outlet_is_refused_on_the_name(): void
    {
        $table = $this->table('D1', $this->a);
        $this->table('D1', $this->b);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame($this->a->id, (int) DiningTable::withoutGlobalScopes()->find($table->id)->outlet_id);
    }

    public function test_renaming_into_a_free_name_while_moving_is_checked_against_the_target(): void
    {
        $table = $this->table('D1', $this->a);
        $this->table('D1', $this->b);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id, 'name' => 'D2'])->assertOk();

        $this->assertSame('D2', DiningTable::withoutGlobalScopes()->find($table->id)->name);
    }

    public function test_sending_the_current_outlet_is_not_a_move(): void
    {
        $table = $this->table('D1', $this->a);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->makeOpenOrder($this->makeShift($cashier), $cashier, ['table_id' => $table->id]);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->a->id, 'seats' => 6])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'moved')->count());
        $this->assertSame(6, DiningTable::withoutGlobalScopes()->find($table->id)->seats);
    }

    public function test_other_changes_in_the_same_request_apply_together_with_the_move(): void
    {
        $table = $this->table('D1', $this->a);

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id, 'seats' => 8, 'area' => 'Teras'])->assertOk();

        $fresh = DiningTable::withoutGlobalScopes()->find($table->id);
        $this->assertSame([$this->b->id, 8, 'Teras'], [(int) $fresh->outlet_id, $fresh->seats, $fresh->area]);
    }

    public function test_a_user_without_the_dining_setup_menu_is_still_refused(): void
    {
        $table = $this->table('D1', $this->a);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->putJson("/api/v1/dining-tables/{$table->id}", ['outlet_id' => $this->b->id])->assertForbidden();
    }
}
