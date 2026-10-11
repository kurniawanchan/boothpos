<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US4 — umpan layar stasiun dan tampilan cetak tiket (FR-032, FR-035).
 */
class KitchenFeedTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cook;
    private Station $kitchen;
    private Station $bar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cook = User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => ['kitchen']])->id]);
        $this->actingAs($this->cook, 'sanctum');
        $this->kitchen = $this->makeStation('Dapur');
        $this->bar = $this->makeStation('Bar');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function feed(Station $station, string $query = '')
    {
        return $this->getJson("/api/v1/stations/{$station->id}/tickets{$query}");
    }

    public function test_the_feed_lists_only_that_stations_unfinished_tickets_oldest_first(): void
    {
        $this->makeTicketItem(['name_snapshot' => 'Nasi Goreng'], $this->kitchen, ticketOver: ['sent_at' => now()->subMinutes(5)]);
        $this->makeTicketItem(['name_snapshot' => 'Mie Goreng'], $this->kitchen, ticketOver: ['sent_at' => now()->subMinutes(10)]);
        $this->makeTicketItem(['name_snapshot' => 'Kopi'], $this->bar);

        $tickets = $this->feed($this->kitchen)->assertOk()->json('tickets');

        $this->assertSame(['Mie Goreng', 'Nasi Goreng'], collect($tickets)->map(fn ($t) => $t['items'][0]['name'])->all());
    }

    public function test_a_ticket_carries_what_the_cook_needs(): void
    {
        $user = User::first();
        $table = \App\Models\DiningTable::factory()->create(['name' => '12']);
        $order = $this->makeOpenOrder($this->makeShift($user), $user, ['table_id' => $table->id, 'service_type' => 'dine_in']);
        $this->makeTicketItem(
            ['name_snapshot' => 'Es Kopi', 'qty' => 2, 'note' => 'tanpa es', 'modifiers' => [['group' => 'Size', 'option' => 'Large', 'price_delta' => '5000.00']]],
            $this->bar, $order, ['round_no' => 2],
        );

        $ticket = $this->feed($this->bar)->assertOk()->json('tickets.0');

        $this->assertSame(2, $ticket['round_no']);
        $this->assertSame($order->order_number, $ticket['order']['order_number']);
        $this->assertSame('12', $ticket['table']['name']);
        $this->assertSame('Es Kopi', $ticket['items'][0]['name']);
        $this->assertSame(2, $ticket['items'][0]['qty']);
        $this->assertSame('tanpa es', $ticket['items'][0]['note']);
        $this->assertSame('Large', $ticket['items'][0]['modifiers'][0]['option']);
        $this->assertSame('new', $ticket['items'][0]['status']);
        $this->assertIsInt($ticket['waiting_seconds']);
    }

    public function test_a_takeaway_ticket_shows_the_label_instead_of_a_table(): void
    {
        $user = User::first();
        $order = $this->makeOpenOrder($this->makeShift($user), $user, ['service_type' => 'takeaway', 'tab_label' => 'Budi']);
        $this->makeTicketItem([], $this->kitchen, $order);

        $ticket = $this->feed($this->kitchen)->json('tickets.0');

        $this->assertNull($ticket['table']);
        $this->assertSame('Budi', $ticket['tab_label']);
    }

    public function test_finished_items_stay_visible_for_thirty_minutes_then_drop_off(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->makeTicketItem(['name_snapshot' => 'Baru Selesai', 'status' => 'served', 'served_at' => now()->subMinutes(10)], $this->kitchen);
        $this->makeTicketItem(['name_snapshot' => 'Sudah Lama', 'status' => 'served', 'served_at' => now()->subMinutes(45)], $this->kitchen);
        $this->makeTicketItem(['name_snapshot' => 'Dibatalkan', 'status' => 'cancelled', 'cancelled_at' => now()->subMinutes(5), 'cancel_reason' => 'habis'], $this->kitchen);

        $items = collect($this->feed($this->kitchen)->json('tickets'))->flatMap(fn ($t) => $t['items']);

        $this->assertEqualsCanonicalizing(['Baru Selesai', 'Dibatalkan'], $items->pluck('name')->all());
        $cancelled = $items->firstWhere('name', 'Dibatalkan');
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('habis', $cancelled['cancel_reason']);
    }

    public function test_a_ticket_with_mixed_items_shows_the_unfinished_and_the_recently_finished_ones_only(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $user = User::first();
        $order = $this->makeOpenOrder($this->makeShift($user), $user);
        $first = $this->makeTicketItem(['name_snapshot' => 'Lama Selesai', 'status' => 'served', 'served_at' => now()->subHour()], $this->kitchen, $order);
        $first->ticket->items()->create(['name_snapshot' => 'Masih Dimasak', 'qty' => 1, 'status' => 'preparing', 'preparing_at' => now()]);

        $names = collect($this->feed($this->kitchen)->json('tickets.0.items'))->pluck('name')->all();

        $this->assertSame(['Masih Dimasak'], $names);
    }

    public function test_a_fully_finished_old_ticket_is_not_listed_at_all(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->makeTicketItem(['status' => 'served', 'served_at' => now()->subHours(3)], $this->kitchen);

        $this->assertSame([], $this->feed($this->kitchen)->json('tickets'));
    }

    public function test_an_unchanged_feed_short_circuits_and_any_change_alters_the_version(): void
    {
        $item = $this->makeTicketItem([], $this->kitchen);

        $first = $this->feed($this->kitchen)->assertOk()->json();
        $this->assertNotEmpty($first['version']);

        $this->feed($this->kitchen, '?version='.$first['version'])->assertOk()->assertExactJson(['unchanged' => true, 'version' => $first['version']]);

        $item->update(['status' => 'preparing', 'preparing_at' => now()]);
        $second = $this->feed($this->kitchen, '?version='.$first['version'])->assertOk()->json();

        $this->assertArrayNotHasKey('unchanged', $second);
        $this->assertNotSame($first['version'], $second['version']);
    }

    public function test_the_version_also_changes_when_an_item_ages_out_of_the_recent_window(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->makeTicketItem(['status' => 'served', 'served_at' => now()->subMinutes(29)], $this->kitchen);
        $before = $this->feed($this->kitchen)->json('version');

        Carbon::setTestNow('2026-10-04 12:05:00'); // sekarang 34 menit sejak selesai

        $after = $this->feed($this->kitchen, '?version='.$before)->json();
        $this->assertArrayNotHasKey('unchanged', $after);
        $this->assertSame([], $after['tickets']);
    }

    public function test_the_version_is_stable_while_only_the_waiting_time_passes(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->makeTicketItem([], $this->kitchen);
        $version = $this->feed($this->kitchen)->json('version');

        Carbon::setTestNow('2026-10-04 12:00:20');

        $this->feed($this->kitchen, '?version='.$version)->assertExactJson(['unchanged' => true, 'version' => $version]);
    }

    public function test_the_feed_needs_the_kitchen_permission_and_stays_inside_the_data_mode(): void
    {
        $demoStation = ModeGate::runAs('demo', fn () => $this->makeStation('Demo'));
        $this->feed($demoStation)->assertNotFound();

        $this->actingAs(User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => ['floor']])->id]), 'sanctum');
        $this->feed($this->kitchen)->assertForbidden();
    }

    public function test_an_inactive_station_still_shows_tickets_already_sent_to_it(): void
    {
        $this->makeTicketItem([], $this->kitchen);
        $this->kitchen->update(['is_active' => false]);

        $this->assertCount(1, $this->feed($this->kitchen)->assertOk()->json('tickets'));
    }

    // --- tampilan cetak ----------------------------------------------------------

    public function test_a_ticket_can_be_fetched_whole_for_the_print_view(): void
    {
        $item = $this->makeTicketItem(['status' => 'served', 'served_at' => now()->subHours(2), 'name_snapshot' => 'Lama'], $this->kitchen);
        $item->ticket->items()->create(['name_snapshot' => 'Baru', 'qty' => 2, 'status' => 'new']);

        $res = $this->getJson("/api/v1/tickets/{$item->kitchen_ticket_id}")->assertOk();

        // Cetak memuat SEMUA item tiket, bukan hanya yang masih relevan di layar.
        $this->assertEqualsCanonicalizing(['Lama', 'Baru'], collect($res->json('items'))->pluck('name')->all());
        $res->assertJsonPath('station.name', 'Dapur')->assertJsonStructure(['id', 'round_no', 'sent_at', 'order' => ['order_number'], 'items']);
    }

    public function test_the_print_view_is_open_to_kitchen_and_floor_but_not_to_others(): void
    {
        $item = $this->makeTicketItem([], $this->kitchen);
        $id = $item->kitchen_ticket_id;

        $this->getJson("/api/v1/tickets/{$id}")->assertOk(); // kitchen

        $this->actingAs(User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => ['floor']])->id]), 'sanctum');
        $this->getJson("/api/v1/tickets/{$id}")->assertOk();

        $this->actingAs(User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => ['dashboard']])->id]), 'sanctum');
        $this->getJson("/api/v1/tickets/{$id}")->assertForbidden();
    }
}
