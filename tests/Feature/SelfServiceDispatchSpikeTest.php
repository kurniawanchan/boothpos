<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\User;
use App\Services\KitchenService;
use App\Services\TabService;
use App\Services\TabSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 090 / SPIKE (gerbang desain, research D3) — membuktikan di MySQL bahwa rangkaian yang akan dipakai dispatch
 * pesan mandiri berjalan dengan layanan yang SUDAH ADA:
 *
 *   TabService::open (tanpa meja, tanpa terminal) → addLine → KitchenService::send
 *     → TabSettlementService::settle (QRIS terverifikasi) → isi `table_id` SETELAH order completed.
 *
 * Mengapa `table_id` diisi belakangan: indeks `uniq_orders_open_table_key` hanya menjamin satu order OPEN per meja,
 * jadi membuka order open di meja yang sudah punya tab kasir akan bentrok. Order yang sudah `completed` tidak ikut
 * indeks itu. Bila tes ini gagal, STOP dan tinjau ulang research D3 sebelum mengerjakan US6.
 */
class SelfServiceDispatchSpikeTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'cashier']);
    }

    /** @return array{order: Order, ticket: KitchenTicket, table: DiningTable, stationId: int} */
    private function runChain(DiningTable $table, int $qty = 2): array
    {
        $shift = $this->makeShift($this->staff);
        $kitchen = $this->makeStation('Dapur');
        $item = $this->makeMenuItem(categoryStation: $kitchen, stock: 10, price: 20000);

        $tabs = app(TabService::class);
        $order = $tabs->open(['session_id' => $shift->id, 'local_ref' => (string) Str::uuid()], $this->staff);
        $order = $tabs->addLine($order, ['variant_id' => $item->id, 'qty' => $qty], $this->staff);

        app(KitchenService::class)->send($order, $this->staff);

        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
        $total = (float) $order->fresh()->total_amount;
        app(TabSettlementService::class)->settle($order->fresh(), [
            'client_ref' => (string) Str::uuid(),
            'payments' => [['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => $total, 'proof_token' => $this->fakeProofToken()]],
        ], $this->staff);

        // Baru sekarang order completed: meja dan penanda asal diisi. forceFill karena keduanya sengaja tidak fillable.
        $order = $order->fresh();
        $order->forceFill(['table_id' => $table->id, 'source' => Order::SOURCE_SELF_SERVICE])->save();

        return [
            'order' => $order->fresh(),
            'ticket' => KitchenTicket::where('order_id', $order->id)->firstOrFail(),
            'table' => $table,
            'stationId' => $kitchen->id,
            'variantId' => $item->id,
        ];
    }

    public function test_the_chain_works_for_an_order_without_a_table_and_without_a_terminal(): void
    {
        $table = DiningTable::factory()->create(['name' => 'Meja 5']);

        $r = $this->runChain($table);

        $order = $r['order'];
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame($table->id, (int) $order->table_id);
        $this->assertSame(Order::SOURCE_SELF_SERVICE, $order->source);
        $this->assertNull($order->device_id, 'dispatch tidak boleh bergantung pada terminal');

        // Pembayaran QRIS tercatat terverifikasi oleh jalur yang sudah ada (PaymentRecorder via settle/autoVerify).
        $payment = $order->payments()->firstOrFail();
        $this->assertSame('qr_ewallet', $payment->method);
        $this->assertSame('verified', $payment->verification);
        $this->assertEqualsWithDelta((float) $order->total_amount, (float) $payment->amount, 0.001);

        // Stok varian berkurang dua (jalur sale yang sama dengan tab biasa).
        $this->assertSame(8, (int) \App\Models\ProductVariant::findOrFail($r['variantId'])->current_stock);
    }

    public function test_the_kitchen_ticket_shows_the_table_even_though_it_was_set_after_sending(): void
    {
        $table = DiningTable::factory()->create(['name' => 'Meja 5']);
        $r = $this->runChain($table);

        $feed = app(KitchenService::class)->feed(\App\Models\Station::findOrFail($r['stationId']));

        $this->assertCount(1, $feed);
        $this->assertSame('Meja 5', $feed->first()->order->table->name);
        $this->assertSame(2, (int) $feed->first()->items->first()->qty);
    }

    public function test_a_table_that_already_has_an_open_cashier_tab_does_not_block_or_change_it(): void
    {
        $table = DiningTable::factory()->create(['name' => 'Meja 5']);
        $cashierShift = $this->makeShift(User::factory()->create(['role' => 'cashier']));
        $cashierTab = app(TabService::class)->open(
            ['session_id' => $cashierShift->id, 'table_id' => $table->id, 'local_ref' => (string) Str::uuid()],
            User::find($cashierShift->user_id),
        );
        $this->assertSame(Order::STATUS_OPEN, $cashierTab->status);

        $r = $this->runChain($table);

        // Order mandiri selesai di meja yang sama; tab kasir tetap open, utuh, dan satu-satunya yang menguasai indeks.
        $this->assertSame($table->id, (int) $r['order']->table_id);
        $reloaded = Order::findOrFail($cashierTab->id);
        $this->assertSame(Order::STATUS_OPEN, $reloaded->status);
        $this->assertSame($table->id, (int) $reloaded->table_id);
        $this->assertSame(1, Order::where('table_id', $table->id)->where('status', Order::STATUS_OPEN)->count());
    }
}
