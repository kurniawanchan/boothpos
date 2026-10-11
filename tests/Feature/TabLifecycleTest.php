<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US2 — membuka, menutup-kosong, serah-terima tab, dan penjagaan shift
 * (FR-004, FR-005, FR-010, FR-011, FR-012).
 */
class TabLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private User $owner;

    /** @var array{armed: bool, table_id: int|null} */
    private static array $race = ['armed' => false, 'table_id' => null];
    private static bool $raceListenerRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    public function test_a_tab_opens_on_a_free_table(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->inArea('Indoor')->create(['name' => '7']);

        $this->apiOpenTab($session->id, $table->id)
            ->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('service_type', 'dine_in')
            ->assertJsonPath('table.id', $table->id)
            ->assertJsonPath('table.name', '7')
            ->assertJsonPath('total_amount', '0.00')
            ->assertJsonPath('balance', '0.00')
            ->assertJsonPath('lines', []);

        $floor = collect($this->getJson('/api/v1/floor')->json('areas.0.tables'))->firstWhere('id', $table->id);
        $this->assertSame('occupied', $floor['state']);
    }

    public function test_the_tab_number_is_unique_and_follows_the_sale_numbering(): void
    {
        $session = $this->makeShift($this->cashier);
        $a = $this->apiOpenTab($session->id, DiningTable::factory()->create()->id)->json('order_number');
        $b = $this->apiOpenTab($session->id, DiningTable::factory()->create()->id)->json('order_number');

        $this->assertMatchesRegularExpression('/^TRX-\d{8}-\d{4}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_a_second_tab_on_an_occupied_table_is_refused_and_points_to_the_existing_one(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create();
        $first = $this->apiOpenTab($session->id, $table->id)->json('id');

        $this->apiOpenTab($session->id, $table->id)
            ->assertStatus(409)
            ->assertJsonPath('existing_tab_id', $first);

        $this->assertSame(1, Order::where('table_id', $table->id)->count());
    }

    public function test_losing_the_race_to_open_a_table_is_reported_as_a_conflict_not_a_server_error(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create();

        // Meniru dua pelayan membuka meja yang sama di detik yang sama: tepat
        // sebelum INSERT milik service, tab lain sudah masuk. Pemeriksaan "meja
        // kosong?" sudah lolos, jadi yang menahan hanya indeks UNIQUE database.
        if (! self::$raceListenerRegistered) {
            Order::creating(function (Order $order) {
                if (self::$race['armed'] && $order->table_id === self::$race['table_id']) {
                    self::$race['armed'] = false;
                    $this->makeOpenOrder(
                        \App\Models\CashierSession::first(), User::first(),
                        ['table_id' => $order->table_id, 'service_type' => 'dine_in'],
                    );
                }
            });
            self::$raceListenerRegistered = true;
        }
        self::$race = ['armed' => true, 'table_id' => $table->id];

        $response = $this->apiOpenTab($session->id, $table->id);
        self::$race['armed'] = false;

        // Yang dibuktikan: kalah balapan = 409 yang bisa dipahami (bukan 500), dan
        // tidak ada tab setengah-jadi. CATATAN: pesaing yang disisipkan di dalam
        // event `creating` ikut di-rollback bersama savepoint transaksi tab ini
        // (pada kejadian nyata ia transaksi terpisah yang sudah commit), jadi
        // `existing_tab_id` boleh null di sini — kuncinya tetap ada.
        $response->assertStatus(409)->assertJsonStructure(['message', 'existing_tab_id']);
        $this->assertLessThanOrEqual(1, Order::where('table_id', $table->id)->where('status', 'open')->count());
    }

    public function test_a_takeaway_tab_needs_no_table(): void
    {
        $session = $this->makeShift($this->cashier);

        $this->apiOpenTab($session->id, null, ['tab_label' => 'Budi'])
            ->assertCreated()
            ->assertJsonPath('service_type', 'takeaway')
            ->assertJsonPath('table', null)
            ->assertJsonPath('tab_label', 'Budi');
    }

    public function test_an_inactive_table_cannot_receive_a_new_tab(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->inactive()->create();

        $this->apiOpenTab($session->id, $table->id)->assertStatus(409);
    }

    public function test_a_tab_cannot_be_opened_without_an_open_shift(): void
    {
        $session = $this->makeShift($this->cashier, ['status' => 'closed', 'closed_at' => now()]);

        $this->apiOpenTab($session->id)->assertStatus(409);
        $this->assertSame(0, Order::count());
    }

    public function test_a_retried_open_returns_the_first_tab_instead_of_creating_another(): void
    {
        $session = $this->makeShift($this->cashier);
        $ref = $this->newLocalRef();

        $first = $this->apiOpenTab($session->id, null, ['local_ref' => $ref, 'tab_label' => 'A'])->assertCreated()->json('id');
        $second = $this->apiOpenTab($session->id, null, ['local_ref' => $ref, 'tab_label' => 'A'])->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
    }

    public function test_a_local_ref_already_used_by_a_retail_sale_is_not_returned_as_a_tab(): void
    {
        $session = $this->makeShift($this->cashier);
        $ref = $this->newLocalRef();
        $this->makeOpenOrder($session, $this->cashier, ['status' => 'completed', 'local_ref' => $ref]);

        $this->apiOpenTab($session->id, null, ['local_ref' => $ref])->assertStatus(409);
    }

    public function test_a_table_of_the_other_data_mode_is_not_found(): void
    {
        $session = $this->makeShift($this->cashier);
        $demoTable = ModeGate::runAs('demo', fn () => DiningTable::factory()->create());

        $this->apiOpenTab($session->id, $demoTable->id)->assertNotFound();
    }

    public function test_a_customer_of_the_other_data_mode_is_rejected(): void
    {
        $session = $this->makeShift($this->cashier);
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create());

        $this->apiOpenTab($session->id, null, ['customer_id' => $demoCustomer->id])
            ->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    }

    public function test_an_empty_tab_can_be_closed_and_frees_its_table(): void
    {
        $session = $this->makeShift($this->cashier);
        $table = DiningTable::factory()->create();
        $tab = $this->apiOpenTab($session->id, $table->id)->json('id');

        $this->deleteJson("/api/v1/tabs/{$tab}")->assertNoContent();

        $this->assertSame('voided', Order::find($tab)->status);
        $this->apiOpenTab($session->id, $table->id)->assertCreated(); // meja kosong lagi
    }

    public function test_a_tab_with_lines_cannot_be_closed_as_empty(): void
    {
        $session = $this->makeShift($this->cashier);
        $tab = $this->apiOpenTab($session->id)->json('id');
        $this->apiAddLine($tab, $this->makeVariant()->id)->assertCreated();

        $this->deleteJson("/api/v1/tabs/{$tab}")->assertStatus(409);
        $this->assertSame('open', Order::find($tab)->status);
    }

    public function test_only_owner_or_admin_can_hand_a_tab_over_to_another_open_shift(): void
    {
        $from = $this->makeShift($this->cashier);
        $to = $this->makeShift($this->owner);
        $tab = $this->apiOpenTab($from->id)->json('id');

        $this->postJson("/api/v1/tabs/{$tab}/handover", ['session_id' => $to->id])->assertForbidden();

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/tabs/{$tab}/handover", ['session_id' => $to->id])
            ->assertOk()->assertJsonPath('session_id', $to->id);
        $this->assertSame($to->event_id, Order::find($tab)->event_id);
    }

    public function test_a_tab_cannot_be_handed_over_to_a_closed_shift(): void
    {
        $from = $this->makeShift($this->cashier);
        $closed = $this->makeShift($this->owner, ['status' => 'closed', 'closed_at' => now()]);
        $tab = $this->apiOpenTab($from->id)->json('id');

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/tabs/{$tab}/handover", ['session_id' => $closed->id])->assertStatus(409);
    }

    public function test_a_shift_with_an_open_tab_cannot_be_closed_and_lists_the_tab(): void
    {
        $session = $this->makeShift($this->cashier, ['opening_cash' => 100000]);
        $table = DiningTable::factory()->create(['name' => '12']);
        $tab = $this->apiOpenTab($session->id, $table->id);

        $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000])
            ->assertStatus(409)
            ->assertJsonPath('open_tabs.0.id', $tab->json('id'))
            ->assertJsonPath('open_tabs.0.order_number', $tab->json('order_number'))
            ->assertJsonPath('open_tabs.0.table', '12');

        $this->assertSame('open', $session->fresh()->status);

        // Setelah tab ditutup, shift bisa ditutup.
        $this->deleteJson('/api/v1/tabs/'.$tab->json('id'))->assertNoContent();
        $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000])->assertOk();
    }

    public function test_the_tab_endpoints_hide_retail_sales(): void
    {
        $session = $this->makeShift($this->cashier);
        $sale = $this->makeOpenOrder($session, $this->cashier, ['status' => 'completed']);

        $this->getJson("/api/v1/tabs/{$sale->id}")->assertNotFound();
    }

    public function test_the_tab_list_shows_only_open_tabs_of_the_requested_shift(): void
    {
        $a = $this->makeShift($this->cashier);
        $b = $this->makeShift($this->cashier);
        $inA = $this->apiOpenTab($a->id, null, ['tab_label' => 'A'])->json('id');
        $this->apiOpenTab($b->id, null, ['tab_label' => 'B']);
        $done = $this->apiOpenTab($a->id)->json('id');
        $this->deleteJson("/api/v1/tabs/{$done}");

        $ids = collect($this->getJson("/api/v1/tabs?session_id={$a->id}")->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$inA], $ids->all());
    }
}
