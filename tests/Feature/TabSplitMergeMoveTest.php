<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US5 — memindah, menggabung, dan memisah tagihan (FR-013–FR-016).
 */
class TabSplitMergeMoveTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private CashierSession $session;
    private Station $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
        $this->kitchen = $this->makeStation('Dapur');
    }

    private function table(string $name): DiningTable
    {
        return DiningTable::factory()->create(['name' => $name]);
    }

    private function tab(?DiningTable $table = null, ?CashierSession $session = null): int
    {
        return $this->apiOpenTab(($session ?? $this->session)->id, $table?->id)->assertCreated()->json('id');
    }

    private function line(int $tab, float $price = 10000, int $qty = 1, array $extra = []): int
    {
        $variant = $this->makeMenuItem(categoryStation: $this->kitchen, price: $price);

        // Setiap panggilan memakai varian BARU, jadi baris yang baru ditambahkan selalu berid terbesar.
        $ids = $this->apiAddLine($tab, $variant->id, $qty, $extra)->assertCreated()->json('lines.*.id');

        return max($ids);
    }

    // ====================== MOVE =================================================

    public function test_a_tab_moves_to_a_free_table_and_frees_the_old_one(): void
    {
        $old = $this->table('3');
        $new = $this->table('8');
        $tab = $this->tab($old);
        $line = $this->line($tab, 25000);

        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $new->id])
            ->assertOk()->assertJsonPath('table.id', $new->id)->assertJsonPath('lines.0.id', $line)->assertJsonPath('total_amount', '25000.00');

        $this->apiOpenTab($this->session->id, $old->id)->assertCreated(); // meja lama kosong lagi
        $this->apiOpenTab($this->session->id, $new->id)->assertStatus(409);  // meja baru terisi
    }

    public function test_moving_onto_an_occupied_table_points_to_the_tab_there(): void
    {
        $a = $this->table('1');
        $b = $this->table('2');
        $tab = $this->tab($a);
        $other = $this->tab($b);

        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $b->id])
            ->assertStatus(409)->assertJsonPath('existing_tab_id', $other);
        $this->assertSame($a->id, Order::find($tab)->table_id);
    }

    public function test_moving_to_an_inactive_table_or_the_same_table_or_a_closed_tab(): void
    {
        $a = $this->table('1');
        $off = DiningTable::factory()->inactive()->create();
        $tab = $this->tab($a);

        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $off->id])->assertStatus(409);
        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $a->id])->assertOk()->assertJsonPath('table.id', $a->id); // no-op

        $line = $this->line($tab);
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 10000]])->assertOk();
        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $this->table('9')->id])->assertStatus(409);
    }

    public function test_a_takeaway_tab_can_be_seated_at_a_table(): void
    {
        $tab = $this->apiOpenTab($this->session->id, null, ['tab_label' => 'Budi'])->json('id');
        $table = $this->table('5');

        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $table->id])
            ->assertOk()->assertJsonPath('service_type', 'dine_in')->assertJsonPath('table.id', $table->id);
    }

    public function test_moving_to_a_table_of_the_other_data_mode_is_not_found(): void
    {
        $tab = $this->tab($this->table('1'));
        $demo = ModeGate::runAs('demo', fn () => DiningTable::factory()->create());

        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $demo->id])->assertNotFound();
    }

    // ====================== MERGE ================================================

    public function test_merging_moves_every_line_and_ticket_to_the_target_and_frees_the_source_table(): void
    {
        $t1 = $this->table('3');
        $t2 = $this->table('4');
        $target = $this->tab($t1);
        $source = $this->tab($t2);
        $this->line($target, 10000);
        $sentLine = $this->line($source, 20000, 2, ['note' => 'pedas']);
        $this->postJson("/api/v1/tabs/{$source}/send")->assertOk();
        $this->line($source, 5000);
        $movements = StockMovement::count();

        $res = $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $source])->assertOk();

        $res->assertJsonCount(3, 'lines')->assertJsonPath('total_amount', '55000.00');
        $moved = collect($res->json('lines'))->firstWhere('id', $sentLine);
        $this->assertTrue($moved['sent']);
        $this->assertSame('new', $moved['prep_status']);
        $this->assertSame('pedas', $moved['note']);
        $this->assertFalse(collect($res->json('lines'))->last()['sent']); // yang belum terkirim tetap belum terkirim

        $src = Order::find($source);
        $this->assertSame('voided', $src->status);
        $this->assertStringContainsString(Order::find($target)->order_number, $src->void_reason);
        $this->assertEquals(0, $src->total_amount);
        $this->assertSame(0, OrderItem::where('order_id', $source)->count());
        $this->assertSame($movements, StockMovement::count()); // menggabung tidak menyentuh stok

        $this->apiOpenTab($this->session->id, $t2->id)->assertCreated(); // meja sumber kosong
        $this->apiOpenTab($this->session->id, $t1->id)->assertStatus(409);
    }

    public function test_tickets_follow_a_merge_so_the_station_shows_the_surviving_tab(): void
    {
        $target = $this->tab($this->table('3'));
        $source = $this->tab($this->table('4'));
        $this->line($source, 20000);
        $this->postJson("/api/v1/tabs/{$source}/send")->assertOk();

        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $source])->assertOk();

        $ticket = KitchenTicket::first();
        $this->assertSame($target, $ticket->order_id);
        $feed = $this->getJson("/api/v1/stations/{$this->kitchen->id}/tickets")->assertOk()->json('tickets.0');
        $this->assertSame('3', $feed['table']['name']);
        $this->assertSame(Order::find($target)->order_number, $feed['order']['order_number']);
    }

    public function test_identical_unsent_lines_stay_separate_after_a_merge(): void
    {
        $variant = $this->makeMenuItem(categoryStation: $this->kitchen, price: 10000);
        $target = $this->tab($this->table('3'));
        $source = $this->tab($this->table('4'));
        $this->apiAddLine($target, $variant->id);
        $this->apiAddLine($source, $variant->id);

        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $source])->assertOk()->assertJsonCount(2, 'lines');
    }

    public function test_a_merge_is_logged(): void
    {
        $target = $this->tab($this->table('3'));
        $source = $this->tab($this->table('4'));
        $this->line($source);

        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $source])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'merged', 'entity_type' => 'Order', 'entity_id' => $target]);
    }

    public function test_merge_refusals(): void
    {
        $target = $this->tab($this->table('3'));
        $source = $this->tab($this->table('4'));
        $this->line($source, 10000);

        // dirinya sendiri
        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $target])->assertStatus(409);

        // shift berbeda
        $otherSession = $this->makeShift(User::factory()->create(['role' => 'owner']));
        $foreign = $this->apiOpenTab($otherSession->id, $this->table('5')->id)->json('id');
        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $foreign])->assertStatus(409);

        // sumber sudah ada pembayaran (tidak ada logika alokasi)
        $this->apiPay($source, [['method' => 'cash', 'amount' => 3000]])->assertOk();
        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $source])->assertStatus(409);
        $this->assertSame(1, OrderItem::where('order_id', $source)->count());

        // sumber/target tidak terbuka
        $done = $this->tab($this->table('6'));
        $this->line($done, 1000);
        $this->apiPay($done, [['method' => 'cash', 'amount' => 1000]])->assertOk();
        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $done])->assertStatus(409);
        $this->postJson("/api/v1/tabs/{$done}/merge", ['source_tab_id' => $target])->assertStatus(409);
    }

    public function test_a_retail_sale_cannot_be_merged(): void
    {
        $target = $this->tab($this->table('3'));
        $sale = $this->makeOpenOrder($this->session, $this->cashier, ['status' => 'completed']);

        $this->postJson("/api/v1/tabs/{$target}/merge", ['source_tab_id' => $sale->id])->assertNotFound();
    }

    // ====================== SPLIT ================================================

    private function split(int $tab, array $items, array $payments, ?string $ref = null, array $extra = [])
    {
        return $this->postJson("/api/v1/tabs/{$tab}/split", array_merge([
            'items' => $items, 'payments' => $payments, 'client_ref' => $ref ?? $this->newLocalRef(),
        ], $extra));
    }

    public function test_splitting_whole_lines_pays_them_as_a_separate_sale_and_leaves_the_rest_open(): void
    {
        $table = $this->table('7');
        $tab = $this->tab($table);
        $a = $this->line($tab, 10000);
        $b = $this->line($tab, 20000);
        $c = $this->line($tab, 30000);
        $d = $this->line($tab, 40000);
        $movements = StockMovement::count();

        $res = $this->split($tab, [['item_id' => $a, 'qty' => 1], ['item_id' => $c, 'qty' => 1]], [['method' => 'cash', 'amount' => 50000]])->assertCreated();

        $paid = $res->json('paid_order');
        $this->assertSame('completed', $paid['status']);
        $this->assertSame('40000.00', $paid['total_amount']);
        $this->assertSame('10000.00', $paid['change_amount']);
        $this->assertEqualsCanonicalizing([$a, $c], collect($paid['lines'])->pluck('id')->all()); // baris yang SAMA dipindahkan
        $this->assertNotSame(Order::find($tab)->order_number, $paid['order_number']);

        $rest = $res->json('tab');
        $this->assertSame('open', $rest['status']);
        $this->assertEqualsCanonicalizing([$b, $d], collect($rest['lines'])->pluck('id')->all());
        $this->assertSame('60000.00', $rest['total_amount']);

        // Jumlahnya persis sama dengan tagihan semula, stok tidak berubah, meja masih terisi.
        $this->assertEquals(100000, (float) $paid['total_amount'] + (float) $rest['total_amount']);
        $this->assertSame($movements, StockMovement::count());
        $this->apiOpenTab($this->session->id, $table->id)->assertStatus(409);

        $order = Order::find($paid['id']);
        $this->assertSame($tab, $order->split_from_order_id);
        $this->assertSame($table->id, $order->table_id);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(0, Payment::where('order_id', $tab)->count());
    }

    public function test_splitting_part_of_an_unsent_line_shrinks_it_and_clones_the_snapshot(): void
    {
        $tab = $this->tab($this->table('7'));
        $line = $this->line($tab, 33333.34, 3, ['note' => 'tanpa sambal']); // 100.000,02

        $res = $this->split($tab, [['item_id' => $line, 'qty' => 1]], [['method' => 'cash', 'amount' => 33333.34]])->assertCreated();

        $paid = $res->json('paid_order');
        $rest = $res->json('tab');
        $this->assertSame('33333.34', $paid['total_amount']);
        $this->assertSame(1, $paid['lines'][0]['qty']);
        $this->assertSame('tanpa sambal', $paid['lines'][0]['note']);
        $this->assertSame('33333.34', $paid['lines'][0]['unit_price']);
        $this->assertNotSame($line, $paid['lines'][0]['id']); // klon, bukan baris yang sama
        $this->assertSame($line, $rest['lines'][0]['id']);
        $this->assertSame(2, $rest['lines'][0]['qty']);
        $this->assertSame('66666.68', $rest['total_amount']);
        // Tepat sampai sen: 33.333,34 + 66.666,68 = 100.000,02
        $this->assertEquals(10000002, round(((float) $paid['total_amount'] + (float) $rest['total_amount']) * 100));
    }

    public function test_a_sent_line_can_be_split_whole_while_it_is_still_being_prepared(): void
    {
        $tab = $this->tab($this->table('7'));
        $line = $this->line($tab, 20000);
        $this->line($tab, 5000);
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        KitchenTicketItem::where('order_item_id', $line)->update(['status' => 'preparing']);

        $this->split($tab, [['item_id' => $line, 'qty' => 1]], [['method' => 'cash', 'amount' => 20000]])->assertCreated();

        // Dapur tidak terpengaruh: item tiket tetap menunjuk baris yang sama (kini milik penjualan baru).
        $this->assertSame('preparing', KitchenTicketItem::where('order_item_id', $line)->first()->status);
    }

    public function test_part_of_a_sent_line_can_only_be_split_once_it_is_served(): void
    {
        $tab = $this->tab($this->table('7'));
        $line = $this->line($tab, 20000, 3);
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        $item = KitchenTicketItem::where('order_item_id', $line)->first();

        $this->split($tab, [['item_id' => $line, 'qty' => 1]], [['method' => 'cash', 'amount' => 20000]])->assertStatus(409);
        $this->assertSame(3, OrderItem::find($line)->qty);

        $item->update(['status' => 'served', 'served_at' => now()]);
        $this->split($tab, [['item_id' => $line, 'qty' => 1]], [['method' => 'cash', 'amount' => 20000]])->assertCreated();
        $this->assertSame(2, OrderItem::find($line)->qty);
    }

    public function test_a_tab_that_already_has_a_payment_cannot_be_split(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000);
        $this->line($tab, 10000);
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 5000]])->assertOk();

        $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]])->assertStatus(409);
        $this->assertSame(2, OrderItem::where('order_id', $tab)->count());
        $this->assertSame(1, Order::count());
    }

    public function test_splitting_every_line_completes_the_sale_and_closes_the_empty_tab(): void
    {
        $table = $this->table('7');
        $tab = $this->tab($table);
        $a = $this->line($tab, 10000);
        $b = $this->line($tab, 20000);

        $res = $this->split($tab, [['item_id' => $a, 'qty' => 1], ['item_id' => $b, 'qty' => 1]], [['method' => 'cash', 'amount' => 30000]])->assertCreated();

        $this->assertSame('completed', $res->json('paid_order.status'));
        $this->assertSame('voided', $res->json('tab.status'));
        $this->assertSame([], $res->json('tab.lines'));
        $this->assertStringContainsString('pisah tagihan', Order::find($tab)->void_reason);
        $this->apiOpenTab($this->session->id, $table->id)->assertCreated(); // meja kosong
    }

    public function test_an_underpaid_split_changes_nothing(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000);
        $this->line($tab, 20000);

        $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 4000]])->assertStatus(409);

        $this->assertSame(1, Order::count());
        $this->assertSame($tab, OrderItem::find($a)->order_id);
        $this->assertSame(0, Payment::count());
    }

    public function test_the_selection_is_validated(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000, 2);
        $other = $this->tab($this->table('8'));
        $foreign = $this->line($other, 10000);
        $cash = [['method' => 'cash', 'amount' => 99999]];

        $this->split($tab, [], $cash)->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->split($tab, [['item_id' => $a, 'qty' => 0]], $cash)->assertStatus(422);
        $this->split($tab, [['item_id' => $a, 'qty' => 3]], $cash)->assertStatus(409);                       // lebih dari yang ada
        $this->split($tab, [['item_id' => $a, 'qty' => 1], ['item_id' => $a, 'qty' => 1]], $cash)->assertStatus(422); // baris kembar
        $this->split($tab, [['item_id' => $foreign, 'qty' => 1]], $cash)->assertNotFound();                  // baris tab lain
        $this->assertSame(2, OrderItem::find($a)->qty);
    }

    public function test_a_repeated_split_submission_does_not_split_twice(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000);
        $this->line($tab, 20000);
        $ref = $this->newLocalRef();

        $first = $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]], $ref)->assertCreated();
        $again = $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]], $ref)->assertOk();

        $this->assertSame($first->json('paid_order.id'), $again->json('paid_order.id'));
        $this->assertSame(2, Order::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_a_discount_applies_to_the_split_sale_only(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 20000);
        $this->line($tab, 30000);

        $res = $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 18000]], null, ['discount_amount' => 2000])->assertCreated();

        $this->assertSame('18000.00', $res->json('paid_order.total_amount'));
        $this->assertSame('2000.00', $res->json('paid_order.discount_amount'));
        $this->assertSame('30000.00', $res->json('tab.total_amount'));
    }

    public function test_the_split_sale_receipt_names_the_tab_it_came_from(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000);
        $this->line($tab, 20000);
        $res = $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]])->assertCreated();

        $this->getJson('/api/v1/orders/'.$res->json('paid_order.id').'/receipt')
            ->assertOk()->assertJsonPath('split_from_order_number', Order::find($tab)->order_number)->assertJsonPath('table.name', '7');
    }

    public function test_the_split_sale_counts_in_reports_and_the_remaining_tab_does_not(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000);
        $this->line($tab, 20000);
        $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $sales = $this->getJson("/api/v1/reports/sales")->assertOk()->json();

        $this->assertEquals(10000, $sales['totals']['gross_sales']);
        $this->assertCount(1, $sales['transactions']);
    }

    public function test_splitting_needs_the_floor_permission_and_an_open_shift(): void
    {
        $tab = $this->tab($this->table('7'));
        $a = $this->line($tab, 10000);
        $this->line($tab, 10000);

        $this->session->update(['status' => 'closed', 'closed_at' => now()]);
        $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]])->assertStatus(409);
        $this->session->update(['status' => 'open', 'closed_at' => null]);

        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->split($tab, [['item_id' => $a, 'qty' => 1]], [['method' => 'cash', 'amount' => 10000]])->assertForbidden();
        $this->postJson("/api/v1/tabs/{$tab}/move", ['table_id' => $this->table('9')->id])->assertForbidden();
        $this->postJson("/api/v1/tabs/{$tab}/merge", ['source_tab_id' => $tab])->assertForbidden();
    }
}
