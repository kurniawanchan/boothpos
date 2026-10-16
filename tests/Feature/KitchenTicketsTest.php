<?php

namespace Tests\Feature;

use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US4 — mengirim tab ke dapur/bar dan alur status item (FR-029–FR-031, FR-033).
 */
class KitchenTicketsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private Station $kitchen;
    private Station $bar;
    private int $tab;

    private static bool $failListenerRegistered = false;
    private static int $failAtItem = 0;
    private static int $itemsCreated = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->kitchen = $this->makeStation('Dapur');
        $this->bar = $this->makeStation('Bar');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
    }

    private function send(?int $tab = null)
    {
        return $this->postJson('/api/v1/tabs/'.($tab ?? $this->tab).'/send');
    }

    private function userWithKeys(array $keys): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['menu_keys' => $keys])->id]);
    }

    public function test_sending_creates_one_ticket_per_station_plus_one_for_items_without_a_station(): void
    {
        $food = $this->makeMenuItem(categoryStation: $this->kitchen);
        $coffee = $this->makeMenuItem(categoryStation: $this->bar);
        $packaged = $this->makeMenuItem(); // tanpa stasiun sama sekali
        $this->apiAddLine($this->tab, $food->id, 2);
        $this->apiAddLine($this->tab, $coffee->id);
        $this->apiAddLine($this->tab, $packaged->id);

        $res = $this->send()->assertOk();

        $this->assertCount(3, $res->json('tickets'));
        $this->assertSame(3, KitchenTicket::count());
        $byStation = KitchenTicket::with('items')->get()->keyBy(fn ($t) => $t->station_id ?? 'none');
        $this->assertSame(['new'], $byStation[$this->kitchen->id]->items->pluck('status')->unique()->all());
        $this->assertSame(2, $byStation[$this->kitchen->id]->items->first()->qty);
        $this->assertSame(['new'], $byStation[$this->bar->id]->items->pluck('status')->all());

        // Item tanpa stasiun tidak perlu dimasak: langsung siap saji.
        $none = $byStation['none']->items->first();
        $this->assertSame('ready', $none->status);
        $this->assertNotNull($none->ready_at);

        $this->assertSame(0, OrderItem::whereNull('sent_at')->count());
    }

    public function test_the_response_is_the_updated_tab_with_prep_status_and_station_per_line(): void
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);

        $res = $this->send()->assertOk();

        $res->assertJsonPath('tab.lines.0.sent', true)
            ->assertJsonPath('tab.lines.0.prep_status', 'new')
            ->assertJsonPath('tab.lines.0.station.name', 'Dapur')
            ->assertJsonPath('tab.lines.0.ticket_item_id', KitchenTicketItem::first()->id)
            ->assertJsonPath('tickets.0.station.name', 'Dapur')
            ->assertJsonPath('tickets.0.round_no', 1);
    }

    public function test_a_product_station_wins_over_its_category_station(): void
    {
        $item = $this->makeMenuItem(categoryStation: $this->bar, productStation: $this->kitchen);
        $this->apiAddLine($this->tab, $item->id);

        $this->send()->assertOk();

        $this->assertSame($this->kitchen->id, KitchenTicket::first()->station_id);
    }

    public function test_an_inactive_station_is_skipped_in_favour_of_the_next_level(): void
    {
        $this->kitchen->update(['is_active' => false]);
        $item = $this->makeMenuItem(categoryStation: $this->bar, productStation: $this->kitchen);
        $this->apiAddLine($this->tab, $item->id);

        $this->send()->assertOk();

        $this->assertSame($this->bar->id, KitchenTicket::first()->station_id);
    }

    public function test_the_ticket_item_is_a_snapshot_of_the_line(): void
    {
        $item = $this->makeMenuItem(categoryStation: $this->kitchen, price: 30000);
        $line = $this->apiAddLine($this->tab, $item->id, 3, ['note' => 'pedas'])->json('lines.0.id');
        OrderItem::whereKey($line)->update(['modifiers' => [['group_id' => 1, 'group' => 'Level', 'option_id' => 2, 'option' => 'Extra', 'price_delta' => '0.00']]]);

        $this->send()->assertOk();

        $ticketItem = KitchenTicketItem::first();
        $this->assertSame($line, $ticketItem->order_item_id);
        $this->assertSame(3, $ticketItem->qty);
        $this->assertSame('pedas', $ticketItem->note);
        $this->assertSame('Extra', $ticketItem->modifiers[0]['option']);
        $this->assertStringContainsString($item->product->name, $ticketItem->name_snapshot);
    }

    public function test_sending_nothing_new_is_a_conflict(): void
    {
        $this->send()->assertStatus(409); // tab kosong

        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->send()->assertOk();
        $this->send()->assertStatus(409); // tidak ada yang baru
        $this->assertSame(1, KitchenTicket::count());
    }

    public function test_a_later_round_sends_only_the_new_lines_and_numbers_the_round(): void
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->send()->assertOk();

        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->bar)->id);
        $this->send()->assertOk()->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.round_no', 2);

        $this->assertSame(2, KitchenTicket::count());
        $this->assertSame(1, KitchenTicket::where('station_id', $this->bar->id)->first()->items()->count());
    }

    public function test_sent_lines_can_no_longer_be_edited_but_new_ones_can_be_added(): void
    {
        $line = $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id)->json('lines.0.id');
        $this->send()->assertOk();

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 5])->assertStatus(409);
        // Menghapus baris yang sudah dikirim = MEMBATALKAN: alasan wajib (aturan lengkapnya di TabVoidAndCancelTest).
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$line}")->assertStatus(422)->assertJsonValidationErrors(['reason_code']);
        $this->apiAddLine($this->tab, $this->makeMenuItem()->id)->assertCreated();
    }

    public function test_sending_is_atomic_a_failure_midway_leaves_nothing_half_sent(): void
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->bar)->id);

        if (! self::$failListenerRegistered) {
            KitchenTicketItem::creating(function () {
                self::$itemsCreated++;
                if (self::$failAtItem > 0 && self::$itemsCreated >= self::$failAtItem) {
                    throw new \RuntimeException('simulasi gagal di tengah pengiriman');
                }
            });
            self::$failListenerRegistered = true;
        }
        self::$itemsCreated = 0;
        self::$failAtItem = 2;

        try {
            $this->withoutExceptionHandling();
            $this->send();
            $this->fail('Seharusnya melempar');
        } catch (\RuntimeException) {
            // diharapkan
        } finally {
            self::$failAtItem = 0;
        }

        $this->assertSame(0, KitchenTicket::count());
        $this->assertSame(0, KitchenTicketItem::count());
        $this->assertSame(2, OrderItem::whereNull('sent_at')->count());
    }

    public function test_only_an_open_tab_can_be_sent_and_retail_sales_are_not_tabs(): void
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])->assertOk()->assertJsonPath('status', 'completed');
        $this->send()->assertStatus(409);

        $sale = $this->makeOpenOrder($this->makeShift($this->cashier), $this->cashier, ['status' => 'completed']);
        $this->send($sale->id)->assertNotFound();
    }

    public function test_sending_needs_the_floor_permission(): void
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->actingAs($this->userWithKeys(['kitchen']), 'sanctum');

        $this->send()->assertForbidden();
        $this->assertSame(0, KitchenTicket::count());
    }

    // --- alur status ------------------------------------------------------------

    private function sentItem(): KitchenTicketItem
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->send()->assertOk();

        return KitchenTicketItem::first();
    }

    private function setStatus(KitchenTicketItem $item, string $status)
    {
        return $this->patchJson("/api/v1/ticket-items/{$item->id}/status", ['status' => $status]);
    }

    public function test_kitchen_staff_move_an_item_through_preparing_and_ready_with_who_and_when(): void
    {
        $item = $this->sentItem();
        $cook = $this->userWithKeys(['kitchen']);
        $this->actingAs($cook, 'sanctum');

        $this->setStatus($item, 'preparing')->assertOk()->assertJsonPath('status', 'preparing');
        $this->setStatus($item, 'ready')->assertOk()->assertJsonPath('status', 'ready');

        $fresh = $item->fresh();
        $this->assertSame($cook->id, $fresh->preparing_by);
        $this->assertSame($cook->id, $fresh->ready_by);
        $this->assertNotNull($fresh->preparing_at);
        $this->assertNotNull($fresh->ready_at);
        $this->assertNull($fresh->served_at);
    }

    public function test_states_cannot_be_skipped_or_reversed(): void
    {
        $item = $this->sentItem();
        $this->actingAs($this->userWithKeys(['kitchen', 'floor']), 'sanctum');

        $this->setStatus($item, 'ready')->assertStatus(409);   // new → ready
        $this->setStatus($item, 'served')->assertStatus(409);  // new → served
        $this->setStatus($item, 'new')->assertStatus(422);     // bukan nilai yang boleh dikirim

        $this->setStatus($item, 'preparing')->assertOk();
        $this->setStatus($item, 'preparing')->assertStatus(409); // sudah di sana
        $this->setStatus($item, 'served')->assertStatus(409);    // preparing → served
        $this->setStatus($item, 'ready')->assertOk();
        $this->setStatus($item, 'served')->assertOk();
        // 'ready' adalah nilai yang SAH dikirim, tetapi transisinya mundur → konflik, bukan galat bentuk data.
        $this->setStatus($item, 'ready')->assertStatus(409);
    }

    public function test_a_served_or_cancelled_item_is_final(): void
    {
        $item = $this->sentItem();
        $this->actingAs($this->userWithKeys(['kitchen', 'floor']), 'sanctum');
        $item->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'habis']);

        $this->setStatus($item, 'preparing')->assertStatus(409);
        $this->setStatus($item, 'served')->assertStatus(409);
        $this->assertSame('cancelled', $item->fresh()->status);
    }

    public function test_cancelled_cannot_be_set_through_this_endpoint(): void
    {
        $item = $this->sentItem();
        $this->actingAs($this->userWithKeys(['kitchen', 'floor']), 'sanctum');

        $this->setStatus($item, 'cancelled')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('new', $item->fresh()->status);
    }

    public function test_the_floor_permission_alone_can_serve_but_not_cook(): void
    {
        $item = $this->sentItem();
        $item->update(['status' => 'ready', 'ready_at' => now()]);
        $waiter = $this->userWithKeys(['floor']);
        $this->actingAs($waiter, 'sanctum');

        $this->setStatus($item, 'served')->assertOk()->assertJsonPath('status', 'served');
        $this->assertSame($waiter->id, $item->fresh()->served_by);

        $other = $this->makeTicketItem();
        $this->setStatus($other, 'preparing')->assertForbidden();
    }

    public function test_the_kitchen_permission_alone_can_cook_and_also_serve(): void
    {
        $item = $this->sentItem();
        $this->actingAs($this->userWithKeys(['kitchen']), 'sanctum');

        $this->setStatus($item, 'preparing')->assertOk();
        $this->setStatus($item, 'ready')->assertOk();
        $this->setStatus($item, 'served')->assertOk();
    }

    public function test_a_role_with_neither_key_cannot_touch_ticket_items(): void
    {
        $item = $this->sentItem();
        $this->actingAs($this->userWithKeys(['dashboard']), 'sanctum');

        $this->setStatus($item, 'preparing')->assertForbidden();
        $this->assertSame('new', $item->fresh()->status);
    }

    public function test_an_item_of_the_other_data_mode_is_not_found(): void
    {
        $demoItem = ModeGate::runAs('demo', fn () => $this->makeTicketItem());
        $this->actingAs($this->userWithKeys(['kitchen']), 'sanctum');

        $this->setStatus($demoItem, 'preparing')->assertNotFound();
    }

    public function test_the_tab_shows_each_lines_progress_and_the_floor_flags_ready_items(): void
    {
        $item = $this->sentItem();
        $this->actingAs($this->userWithKeys(['kitchen', 'floor']), 'sanctum');

        $this->setStatus($item, 'preparing');
        $this->assertSame('preparing', $this->getJson("/api/v1/tabs/{$this->tab}")->json('lines.0.prep_status'));
        $this->assertSame(0, $this->floorTab()['ready_count']);

        $this->setStatus($item, 'ready');
        $this->assertSame('ready', $this->getJson("/api/v1/tabs/{$this->tab}")->json('lines.0.prep_status'));
        $this->assertSame(1, $this->floorTab()['ready_count']);

        $this->setStatus($item, 'served');
        $this->assertSame(0, $this->floorTab()['ready_count']);
        $this->assertSame('served', $this->getJson("/api/v1/tabs/{$this->tab}")->json('lines.0.prep_status'));
    }

    public function test_the_unsent_count_on_the_floor_drops_to_zero_once_everything_is_sent(): void
    {
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $this->kitchen)->id);
        $this->assertSame(1, $this->floorTab()['unsent_count']);

        $this->send()->assertOk();
        $this->assertSame(0, $this->floorTab()['unsent_count']);
    }

    private function floorTab(): array
    {
        $order = Order::find($this->tab);
        $rows = collect($this->getJson('/api/v1/floor')->json('takeaway_tabs'))->merge(
            collect($this->getJson('/api/v1/floor')->json('areas'))->flatMap(fn ($a) => collect($a['tables'])->pluck('tab')->filter())
        );

        return $rows->firstWhere('id', $order->id);
    }
}
