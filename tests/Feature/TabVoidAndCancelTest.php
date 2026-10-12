<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CashierSession;
use App\Models\DiningTable;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US6 — membatalkan item yang sudah dikirim dan membatalkan tab (FR-018, FR-019, FR-036, FR-037).
 *
 * Aturan stok: dikembalikan HANYA bila item belum mulai dimasak (`new`); begitu `preparing` atau
 * lebih, bahan sudah terpakai → stok tidak kembali, dan pembatalannya hanya boleh owner/admin
 * dengan alasan, tercatat di log aktivitas di dalam transaksi yang sama.
 */
class TabVoidAndCancelTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private User $owner;
    private CashierSession $session;
    private Station $kitchen;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
        $this->kitchen = $this->makeStation('Dapur');
        $this->tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create(['name' => '7'])->id)->json('id');
    }

    /** @return array{0: int, 1: ProductVariant} [id baris, varian] — sudah DIKIRIM ke dapur */
    private function sentLine(int $stock = 10, int $qty = 2, float $price = 10000): array
    {
        $variant = $this->makeMenuItem(categoryStation: $this->kitchen, stock: $stock, price: $price);
        $ids = $this->apiAddLine($this->tab, $variant->id, $qty)->assertCreated()->json('lines.*.id');
        $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();

        return [max($ids), $variant];
    }

    private function cancel(int $line, ?string $reason = 'salah pesan', ?int $tab = null)
    {
        return $this->deleteJson('/api/v1/tabs/'.($tab ?? $this->tab)."/items/{$line}", $reason === null ? [] : ['reason' => $reason]);
    }

    private function setKitchenStatus(int $line, string $status): void
    {
        KitchenTicketItem::where('order_item_id', $line)->update(['status' => $status, "{$status}_at" => now()]);
    }

    private function asOwner(): void
    {
        $this->actingAs($this->owner, 'sanctum');
    }

    // ====================== batal item yang sudah dikirim ==========================

    public function test_cancelling_a_sent_item_before_it_is_cooked_needs_only_a_reason_and_returns_its_stock(): void
    {
        [$line, $variant] = $this->sentLine(stock: 10, qty: 2); // stok 10 → 8

        $res = $this->cancel($line)->assertOk();

        $res->assertJsonCount(0, 'lines')->assertJsonPath('total_amount', '0.00');
        $this->assertSame(10, $variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'type' => 'return', 'qty_change' => 2, 'reference_id' => $line]);
        $this->assertNull(OrderItem::find($line));

        $item = KitchenTicketItem::first();
        $this->assertSame('cancelled', $item->status);
        $this->assertSame('salah pesan', $item->cancel_reason);
        $this->assertSame($this->cashier->id, $item->cancelled_by);
        $this->assertNotNull($item->cancelled_at);
        $this->assertNull($item->order_item_id); // barisnya sudah tidak ada; snapshot tiket tetap
        $this->assertSame(2, $item->qty);
    }

    public function test_the_station_still_sees_the_cancelled_item_so_nobody_cooks_it(): void
    {
        [$line] = $this->sentLine();
        $this->cancel($line, 'pelanggan batal')->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum'); // Kasir punya `kitchen`
        $items = collect($this->getJson("/api/v1/stations/{$this->kitchen->id}/tickets")->json('tickets.0.items'));

        $this->assertSame('cancelled', $items[0]['status']);
        $this->assertSame('pelanggan batal', $items[0]['cancel_reason']);
    }

    public function test_a_reason_is_required_for_a_sent_item(): void
    {
        [$line, $variant] = $this->sentLine();

        $this->cancel($line, null)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->cancel($line, '   ')->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->assertNotNull(OrderItem::find($line));
        $this->assertSame(8, $variant->fresh()->current_stock);
        $this->assertSame('new', KitchenTicketItem::first()->status);
    }

    public function test_an_unsent_item_still_needs_no_reason(): void
    {
        $variant = $this->makeMenuItem(categoryStation: $this->kitchen);
        $line = max($this->apiAddLine($this->tab, $variant->id)->json('lines.*.id'));

        $this->cancel($line, null)->assertOk()->assertJsonCount(0, 'lines');
    }

    /** @return array<string, array{0: string}> */
    public static function startedStatuses(): array
    {
        return ['preparing' => ['preparing'], 'ready' => ['ready'], 'served' => ['served']];
    }

    #[DataProvider('startedStatuses')]
    public function test_once_cooking_has_started_a_cashier_may_not_cancel_the_item(string $status): void
    {
        [$line, $variant] = $this->sentLine();
        $this->setKitchenStatus($line, $status);

        $this->cancel($line)->assertForbidden();

        $this->assertNotNull(OrderItem::find($line));
        $this->assertSame($status, KitchenTicketItem::first()->status);
        $this->assertSame(8, $variant->fresh()->current_stock);
        $this->assertSame(0, ActivityLog::where('action', 'item_cancelled')->count());
    }

    #[DataProvider('startedStatuses')]
    public function test_an_owner_may_cancel_it_with_a_reason_but_the_stock_does_not_come_back(string $status): void
    {
        [$line, $variant] = $this->sentLine(stock: 10, qty: 2);
        $this->setKitchenStatus($line, $status);
        $this->asOwner();

        $res = $this->cancel($line, 'dikembalikan pelanggan')->assertOk();

        $res->assertJsonCount(0, 'lines');
        $this->assertSame(8, $variant->fresh()->current_stock);                                  // bahan sudah terpakai
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $item = KitchenTicketItem::first();
        $this->assertSame('cancelled', $item->status);
        $this->assertSame($this->owner->id, $item->cancelled_by);
        $this->assertSame('dikembalikan pelanggan', $item->cancel_reason);

        $log = ActivityLog::where('action', 'item_cancelled')->first();
        $this->assertNotNull($log, 'pembatalan setelah dimasak wajib tercatat');
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertStringContainsString('dikembalikan pelanggan', $log->description);
        $this->assertStringContainsString($status, json_encode($log->old_values));
    }

    public function test_cancelling_before_cooking_is_also_logged(): void
    {
        [$line] = $this->sentLine();

        $this->cancel($line, 'salah pesan')->assertOk();

        $log = ActivityLog::where('action', 'item_cancelled')->first();
        $this->assertSame($this->cashier->id, $log->user_id);
        $this->assertStringContainsString('salah pesan', $log->description);
    }

    public function test_a_refused_cancellation_leaves_no_trace_anywhere(): void
    {
        // Tab sudah dibayar sebagian: membatalkan baris ini akan membuat total < dibayar → ditolak
        // DI AKHIR transaksi, setelah stok/tiket/baris sempat diubah. Semuanya harus ikut mundur,
        // termasuk baris log (log tidak boleh mengklaim sesuatu yang tidak terjadi).
        [$line, $variant] = $this->sentLine(stock: 10, qty: 2, price: 10000);   // 20.000
        $other = $this->apiAddLine($this->tab, $this->makeMenuItem(price: 10000)->id)->assertCreated();    // total 30.000
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 25000]])->assertOk()->assertJsonPath('status', 'open');

        $this->cancel($line)->assertStatus(409); // total jadi 10.000 < dibayar 25.000

        $this->assertNotNull(OrderItem::find($line));
        $this->assertSame('new', KitchenTicketItem::first()->status);
        $this->assertNull(KitchenTicketItem::first()->cancelled_at);
        $this->assertSame(8, $variant->fresh()->current_stock);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $this->assertSame(0, ActivityLog::where('action', 'item_cancelled')->count());
    }

    public function test_a_line_of_a_completed_order_cannot_be_cancelled_through_the_tab_flow(): void
    {
        [$line] = $this->sentLine(price: 10000);
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])->assertOk()->assertJsonPath('status', 'completed');
        $this->asOwner();

        $this->cancel($line, 'terlambat')->assertStatus(409);

        $this->assertNotNull(OrderItem::find($line));
    }

    public function test_an_untracked_dish_never_moves_stock_when_cancelled(): void
    {
        $dish = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 0, variantOverrides: ['track_stock' => false]);
        $line = max($this->apiAddLine($this->tab, $dish->id)->json('lines.*.id'));
        $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();

        $this->cancel($line)->assertOk();

        $this->assertSame(0, StockMovement::count());
    }

    public function test_cancelling_an_item_of_the_other_tab_or_a_missing_one_is_not_found(): void
    {
        [$line] = $this->sentLine();
        $otherTab = $this->apiOpenTab($this->session->id)->json('id');

        $this->cancel($line, 'x', $otherTab)->assertNotFound();
        $this->cancel(999999)->assertNotFound();
        $this->assertNotNull(OrderItem::find($line));
    }

    // ====================== batal tab ===============================================

    private function voidTab(?string $reason = 'tamu pergi', ?int $tab = null)
    {
        return $this->postJson('/api/v1/tabs/'.($tab ?? $this->tab).'/void', $reason === null ? [] : ['reason' => $reason]);
    }

    public function test_voiding_a_tab_returns_stock_only_for_items_not_yet_cooking_and_cancels_the_open_tickets(): void
    {
        $fresh = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 10, price: 10000);
        $cooking = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 10, price: 10000);
        $served = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 10, price: 10000);
        $unsent = $this->makeMenuItem(categoryStation: $this->kitchen, stock: 10, price: 10000);
        $freshLine = max($this->apiAddLine($this->tab, $fresh->id, 2)->json('lines.*.id'));
        $cookingLine = max($this->apiAddLine($this->tab, $cooking->id, 3)->json('lines.*.id'));
        $servedLine = max($this->apiAddLine($this->tab, $served->id)->json('lines.*.id'));
        $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();
        $this->apiAddLine($this->tab, $unsent->id, 4)->assertCreated(); // belum dikirim
        $this->setKitchenStatus($cookingLine, 'preparing');
        $this->setKitchenStatus($servedLine, 'served');
        $this->asOwner();

        $this->voidTab()->assertOk()->assertJsonPath('status', 'voided');

        $this->assertSame(10, $fresh->fresh()->current_stock);    // belum dimasak → kembali
        $this->assertSame(7, $cooking->fresh()->current_stock);   // sedang dimasak → tidak kembali
        $this->assertSame(9, $served->fresh()->current_stock);    // sudah diantar → tidak kembali
        $this->assertSame(10, $unsent->fresh()->current_stock);   // belum dikirim → kembali

        $byLine = KitchenTicketItem::all()->keyBy('order_item_id');
        $this->assertSame('cancelled', $byLine[$freshLine]->status);
        $this->assertSame('cancelled', $byLine[$cookingLine]->status);
        $this->assertSame('served', $byLine[$servedLine]->status); // yang sudah final tetap final
        $this->assertSame('tamu pergi', $byLine[$cookingLine]->cancel_reason);
        $this->assertSame($this->owner->id, $byLine[$cookingLine]->cancelled_by);

        $order = Order::find($this->tab);
        $this->assertSame('voided', $order->status);
        $this->assertSame('tamu pergi', $order->void_reason);
    }

    public function test_voiding_frees_the_table_and_is_logged(): void
    {
        $this->sentLine();
        $this->asOwner();

        $this->voidTab()->assertOk();

        $table = DiningTable::where('name', '7')->first();
        $this->apiOpenTab($this->session->id, $table->id)->assertCreated();
        $log = ActivityLog::where('action', 'tab_voided')->first();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame($this->tab, $log->entity_id);
        $this->assertStringContainsString('tamu pergi', $log->description);
    }

    public function test_a_voided_tab_no_longer_counts_anywhere(): void
    {
        $this->sentLine(price: 10000);
        $this->asOwner();
        $this->voidTab()->assertOk();

        $this->getJson("/api/v1/tabs/{$this->tab}")->assertNotFound();
        $sales = $this->getJson("/api/v1/reports/sales")->assertOk()->json();
        $this->assertEquals(0, $sales['totals']['gross_sales']);
        $this->assertSame([], collect($this->getJson('/api/v1/tabs')->json('data'))->pluck('id')->all());
    }

    public function test_only_owner_or_admin_may_void_a_tab_and_a_reason_is_required(): void
    {
        $this->sentLine();

        $this->voidTab()->assertForbidden();               // kasir
        $this->assertSame('open', Order::find($this->tab)->status);

        $this->asOwner();
        $this->voidTab(null)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->voidTab('  ')->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame('open', Order::find($this->tab)->status);
    }

    public function test_a_tab_with_any_recorded_payment_cannot_be_voided(): void
    {
        $this->sentLine(price: 10000);
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 5000]])->assertOk();
        $this->asOwner();

        $this->voidTab()->assertStatus(409);

        $this->assertSame('open', Order::find($this->tab)->status);
        $this->assertSame('new', KitchenTicketItem::first()->status);
    }

    public function test_a_tab_that_is_not_open_cannot_be_voided_again(): void
    {
        $this->sentLine(price: 10000);
        $this->asOwner();
        $this->voidTab()->assertOk();

        $this->voidTab()->assertStatus(409);
        $this->voidTab('lagi', $this->makeOpenOrder($this->session, $this->cashier, ['status' => 'completed'])->id)->assertNotFound(); // penjualan ritel
    }

    public function test_voiding_a_completed_tab_sale_follows_the_unchanged_sale_void_rule(): void
    {
        [$line, $variant] = $this->sentLine(stock: 10, qty: 2, price: 10000); // stok 8
        $this->setKitchenStatus($line, 'preparing');
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])->assertOk()->assertJsonPath('status', 'completed');
        $this->asOwner();

        $this->postJson("/api/v1/orders/{$this->tab}/void", ['reason' => 'koreksi'])->assertOk();

        // Aturan void penjualan SELESAI tidak berubah: seluruh baris dikembalikan.
        $this->assertSame(10, $variant->fresh()->current_stock);
        $this->assertSame('voided', Order::find($this->tab)->status);
    }
}
