<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\KitchenTicket;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 071 (US2) — PUT /stations/{id} dengan `outlet_id` memindahkan stasiun: ditolak (409) bila dipakai kategori/item menu atau
 * punya tiket dapur belum selesai; keunikan nama di outlet tujuan; izin outlet; log `moved`; riwayat tiket tak berubah.
 */
class StationMoveTest extends TestCase
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

    private function station(string $name, $outlet): Station
    {
        $station = new Station(['name' => $name]);
        $station->outlet_id = $outlet->id;
        $station->save();

        return $station;
    }

    private function outletOf(Station $s): int
    {
        return (int) Station::withoutGlobalScopes()->find($s->id)->outlet_id;
    }

    public function test_an_unused_station_moves_and_the_move_is_logged(): void
    {
        $station = $this->station('Dapur', $this->a);

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->b->id])
            ->assertOk()->assertJsonPath('outlet_id', $this->b->id);

        $this->assertSame($this->b->id, $this->outletOf($station));
        $log = ActivityLog::where('entity_type', 'Station')->where('entity_id', $station->id)->where('action', 'moved')->firstOrFail();
        $this->assertSame($this->a->id, (int) $log->old_values['outlet_id']);
        $this->assertSame($this->b->id, (int) $log->new_values['outlet_id']);
    }

    public function test_a_station_used_by_a_category_is_refused_with_the_reason(): void
    {
        $station = $this->station('Dapur', $this->a);
        Category::factory()->create(['station_id' => $station->id]);

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->b->id])
            ->assertStatus(409)->assertJsonPath('message', __('restaurant.station_move_in_use'));

        $this->assertSame($this->a->id, $this->outletOf($station));
        $this->assertSame(0, ActivityLog::where('action', 'moved')->count());
    }

    public function test_a_station_used_by_a_product_is_refused(): void
    {
        $station = $this->station('Bar', $this->a);
        Product::factory()->create(['station_id' => $station->id, 'category_id' => Category::factory()->create()->id]);

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->b->id])
            ->assertStatus(409)->assertJsonPath('message', __('restaurant.station_move_in_use'));
    }

    public function test_unfinished_kitchen_tickets_refuse_the_move_and_finished_ones_stay_where_they_were(): void
    {
        $busy = $this->station('Sibuk', $this->a);
        $this->makeTicketItem(['status' => 'preparing'], $busy);
        $done = $this->station('Selesai', $this->a);
        $item = $this->makeTicketItem(['status' => 'served'], $done);
        $ticketOutlet = (int) KitchenTicket::withoutGlobalScopes()->find($item->kitchen_ticket_id)->outlet_id;

        $this->putJson("/api/v1/stations/{$busy->id}", ['outlet_id' => $this->b->id])
            ->assertStatus(409)->assertJsonPath('message', __('restaurant.station_move_open_tickets'));
        $this->putJson("/api/v1/stations/{$done->id}", ['outlet_id' => $this->b->id])->assertOk();

        $this->assertSame($this->a->id, $this->outletOf($busy));
        $this->assertSame($ticketOutlet, (int) KitchenTicket::withoutGlobalScopes()->find($item->kitchen_ticket_id)->outlet_id, 'riwayat tiket tidak dipindah');
    }

    public function test_an_outlet_the_user_may_not_see_is_forbidden(): void
    {
        $station = $this->station('Dapur', $this->a);
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$this->a]);
        $this->actingAs($user, 'sanctum');

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->b->id])
            ->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');

        $this->assertSame($this->a->id, $this->outletOf($station));
    }

    public function test_an_unknown_outlet_is_a_validation_error(): void
    {
        $station = $this->station('Dapur', $this->a);

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('outlet_id');
    }

    public function test_a_name_already_used_at_the_target_outlet_is_refused_even_without_sending_the_name(): void
    {
        $station = $this->station('Dapur', $this->a);
        $this->station('Dapur', $this->b);

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->b->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame($this->a->id, $this->outletOf($station));
    }

    public function test_the_name_is_also_checked_across_data_modes(): void
    {
        $station = $this->station('Dapur', $this->a);
        ModeGate::runAs('demo', fn () => $this->station('Dapur', $this->b));

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->b->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_sending_the_current_outlet_is_not_a_move_even_when_the_station_is_in_use(): void
    {
        $station = $this->station('Dapur', $this->a);
        Category::factory()->create(['station_id' => $station->id]);

        $this->putJson("/api/v1/stations/{$station->id}", ['outlet_id' => $this->a->id, 'display_order' => 3])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'moved')->count());
    }

    public function test_other_changes_apply_together_with_the_move_and_none_when_it_is_refused(): void
    {
        $free = $this->station('Bebas', $this->a);
        $used = $this->station('Terpakai', $this->a);
        Category::factory()->create(['station_id' => $used->id]);

        $this->putJson("/api/v1/stations/{$free->id}", ['outlet_id' => $this->b->id, 'name' => 'Bebas 2', 'display_order' => 5])->assertOk();
        $this->putJson("/api/v1/stations/{$used->id}", ['outlet_id' => $this->b->id, 'name' => 'Terpakai 2'])->assertStatus(409);

        $this->assertSame(['Bebas 2', $this->b->id], [Station::withoutGlobalScopes()->find($free->id)->name, $this->outletOf($free)]);
        $this->assertSame(['Terpakai', $this->a->id], [Station::withoutGlobalScopes()->find($used->id)->name, $this->outletOf($used)]);
    }
}
