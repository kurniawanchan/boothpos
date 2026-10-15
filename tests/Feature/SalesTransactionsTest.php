<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Daftar transaksi halaman Sales: HANYA order POS (pre-order sengaja tidak ditampilkan di
 * sini), transaksi batal bila diminta, status verifikasi pembayaran, uang tunai vs
 * non-tunai per baris, shift kasir, pratinjau item, dan margin (khusus owner/admin).
 * makePreorder() tetap ada untuk membuktikan bahwa pre-order TIDAK muncul.
 */
class SalesTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $owner;

    private Event $event;

    private CashierSession $session;

    private $variantA;

    private $variantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Pemilik']);
        $this->actingAs($this->cashier, 'sanctum');

        $this->event = Event::factory()->create(['status' => 'active', 'name' => 'Sakana Fest']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 100000,
        ]);

        $category = Category::factory()->create();
        $artistA = Artist::factory()->create(['name' => 'Nekoyama Studio']);
        $artistB = Artist::factory()->create(['name' => 'Yukishiro Works']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id, 'name' => 'Akatsuki Keychain', 'is_preorder' => true]);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id, 'name' => 'Sakura Sticker', 'is_preorder' => true]);
        $this->variantA = $productA->variants()->create(['sku' => 'NEKKYAKT0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
        $this->variantB = $productB->variants()->create(['sku' => 'YUKSTSAK0001', 'sell_price' => 50000, 'cost_price' => 20000, 'current_stock' => 100]);
    }

    /** Order POS: 3 × 10.000 + 1 × 50.000 (diskon item 2.000) = 78.000; modal 3×4.000 + 20.000 = 32.000. */
    private function makeOrder(array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [
                ['variant_id' => $this->variantA->id, 'qty' => 3],
                ['variant_id' => $this->variantB->id, 'qty' => 1, 'discount_amount' => 2000],
            ],
            'payments' => [['method' => 'cash', 'amount' => 80000]], // kembalian 2.000
        ], $overrides), $this->cashier);
    }

    /** Pre-order 2 × 50.000 = 100.000, dibayar sebagian $paid → jumlah diakui = $paid. */
    private function makePreorder(float $paid, array $overrides = []): array
    {
        $preorder = $this->postJson('/api/v1/preorders', array_merge([
            'customer_id' => Customer::factory()->create(['name' => 'Pembeli PO'])->id,
            'event_id' => $this->event->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variantB->id, 'qty' => 2]],
        ], $overrides))->assertCreated()->json();

        if ($paid > 0) {
            $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", ['method' => 'cash', 'amount' => $paid, 'purpose' => 'down_payment'])->assertCreated();
        }

        return $preorder;
    }

    private function report(array $query = []): array
    {
        $query = array_merge(['event_id' => $this->event->id], $query);

        return $this->getJson('/api/v1/reports/sales?'.http_build_query($query))->assertOk()->json();
    }

    private function rows(array $query = []): \Illuminate\Support\Collection
    {
        return collect($this->report($query)['transactions']);
    }

    // --- hanya transaksi POS ----------------------------------------------------------

    public function test_rows_carry_a_unique_key_and_the_order_status(): void
    {
        $order = $this->makeOrder();

        $row = $this->rows()->firstWhere('key', "order:{$order->id}");

        $this->assertSame('completed', $row['status']);
        $this->assertSame($order->order_number, $row['order_number']);
        $this->assertArrayNotHasKey('type', $row); // satu-satunya jenis baris kini order POS
    }

    public function test_preorders_never_appear_in_the_sales_rows_however_much_is_paid(): void
    {
        $order = $this->makeOrder();
        $partlyPaid = $this->makePreorder(40000);
        $fullyPaid = $this->makePreorder(100000);

        $keys = $this->rows()->pluck('key')->all();

        $this->assertSame(["order:{$order->id}"], $keys);
        foreach ([$partlyPaid, $fullyPaid] as $preorder) {
            $this->assertNotContains("preorder:{$preorder['id']}", $keys);
        }
    }

    public function test_the_sales_page_figures_are_pos_only_while_the_shared_report_totals_still_include_preorders(): void
    {
        $this->makeOrder();          // 3×10.000 + 50.000 − diskon item 2.000 = 78.000 (4 unit)
        $this->makePreorder(40000);  // pendapatan pre-order yang diakui: 40.000 (2 unit × 0,4 = 0,8)

        $totals = $this->report()['totals'];

        // Angka khusus halaman Sales: hanya transaksi POS.
        $this->assertEquals(78000, (float) $totals['pos_net_sales']);
        $this->assertEquals(80000, (float) $totals['pos_gross_sales']);   // sebelum diskon item
        $this->assertEquals(4, (float) $totals['pos_unit_count']);
        $this->assertSame(1, (int) $totals['order_count']);
        // Angka laporan bersama TIDAK berubah: Dashboard & Laporan sengaja memuat pre-order.
        $this->assertEquals(118000, (float) $totals['net_sales']);
        $this->assertGreaterThan((float) $totals['pos_unit_count'], (float) $totals['unit_count']);
    }

    public function test_rows_are_ordered_newest_first(): void
    {
        $this->travelTo(now()->subHours(2));
        $old = $this->makeOrder();
        $this->travelTo(now()->addHour());
        $new = $this->makeOrder();
        $this->travelBack();

        $this->assertSame(["order:{$new->id}", "order:{$old->id}"], $this->rows()->pluck('key')->all());
    }

    // --- batal ----------------------------------------------------------------------

    public function test_voided_orders_are_hidden_by_default_and_never_counted(): void
    {
        $kept = $this->makeOrder();
        $voided = $this->makeOrder();
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$voided->id}/void", ['reason' => 'Salah input'])->assertOk();

        $report = $this->report();

        $this->assertSame(["order:{$kept->id}"], collect($report['transactions'])->pluck('key')->all());
        $this->assertSame(1, (int) $report['totals']['order_count']);
    }

    public function test_voided_orders_can_be_included_for_audit_without_changing_the_totals(): void
    {
        $kept = $this->makeOrder();
        $voided = $this->makeOrder();
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$voided->id}/void", ['reason' => 'Salah input'])->assertOk();

        $report = $this->report(['include_voided' => 1]);
        $rows = collect($report['transactions'])->keyBy('key');

        $this->assertCount(2, $rows);
        $this->assertSame('voided', $rows["order:{$voided->id}"]['status']);
        $this->assertSame('Salah input', $rows["order:{$voided->id}"]['void_reason']);
        $this->assertSame('completed', $rows["order:{$kept->id}"]['status']);
        $this->assertSame(1, (int) $report['totals']['order_count']); // total TIDAK ikut berubah
    }

    // --- pembayaran -------------------------------------------------------------------

    public function test_cash_and_non_cash_are_split_per_row_net_of_change(): void
    {
        $order = $this->makeOrder(); // total 78.000, tunai 80.000, kembalian 2.000

        $row = $this->rows()->firstWhere('key', "order:{$order->id}");

        $this->assertSame('78000.00', $row['cash_amount']);   // tunai bersih (setelah kembalian)
        $this->assertSame('0.00', $row['noncash_amount']);
    }

    public function test_non_cash_amounts_are_reported_separately(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet', 'provider' => 'GoPay']);
        // OrderService menuntut bukti unggahan untuk pembayaran non-tunai, jadi dibuat langsung.
        $order = $this->makeOrder();
        $order->payments()->delete();
        $order->payments()->create(['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'purpose' => 'full', 'amount' => 78000, 'verification' => 'verified', 'paid_at' => now()]);

        $row = $this->rows()->firstWhere('key', "order:{$order->id}");

        $this->assertSame('0.00', $row['cash_amount']);
        $this->assertSame('78000.00', $row['noncash_amount']);
    }

    public function test_payment_state_reports_the_worst_verification_among_payments(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer', 'provider' => 'BCA']);
        $verified = $this->makeOrder();
        $pending = $this->makeOrder();
        $pending->payments()->create(['method' => 'bank_transfer', 'channel_id' => $channel->id, 'purpose' => 'full', 'amount' => 1000, 'verification' => 'pending', 'paid_at' => now()]);
        $rejected = $this->makeOrder();
        $rejected->payments()->create(['method' => 'bank_transfer', 'channel_id' => $channel->id, 'purpose' => 'full', 'amount' => 1000, 'verification' => 'pending', 'paid_at' => now()]);
        $rejected->payments()->create(['method' => 'bank_transfer', 'channel_id' => $channel->id, 'purpose' => 'full', 'amount' => 1000, 'verification' => 'rejected', 'paid_at' => now()]);

        $rows = $this->rows()->keyBy('key');

        $this->assertSame('verified', $rows["order:{$verified->id}"]['payment_state']);
        $this->assertSame('pending', $rows["order:{$pending->id}"]['payment_state']);
        $this->assertSame('rejected', $rows["order:{$rejected->id}"]['payment_state']); // rejected mengalahkan pending
    }

    // --- shift ------------------------------------------------------------------------

    public function test_orders_expose_their_session_and_the_response_lists_those_sessions(): void
    {
        $order = $this->makeOrder();

        $report = $this->report();
        $rows = collect($report['transactions'])->keyBy('key');

        $this->assertSame($this->session->id, $rows["order:{$order->id}"]['session_id']);

        $session = collect($report['sessions'])->firstWhere('id', $this->session->id);
        $this->assertSame('Kasir Satu', $session['cashier_name']);
        $this->assertSame('100000.00', $session['opening_cash']);
        $this->assertSame('open', $session['status']);
        $this->assertNull($session['closed_at']);
    }

    // --- pratinjau item -----------------------------------------------------------------

    public function test_rows_preview_the_two_biggest_lines_and_count_the_rest(): void
    {
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Poster']);
        $variantC = $product->variants()->create(['sku' => 'POSTER0001', 'sell_price' => 5000, 'cost_price' => 1000, 'current_stock' => 50]);
        $order = $this->makeOrder(['items' => [
            ['variant_id' => $this->variantA->id, 'qty' => 3],   // 30.000
            ['variant_id' => $this->variantB->id, 'qty' => 1],   // 50.000  ← terbesar
            ['variant_id' => $variantC->id, 'qty' => 1],         // 5.000
        ], 'payments' => [['method' => 'cash', 'amount' => 90000]]]);

        $row = $this->rows()->firstWhere('key', "order:{$order->id}");

        // name_snapshot = produk — varian, jadi pembacanya tahu varian yang mana.
        $this->assertSame(['Sakura Sticker — Standard', 'Akatsuki Keychain — Standard'], collect($row['items_preview'])->pluck('name')->all());
        $this->assertSame(1, $row['items_more']);
    }

    // --- margin: hanya owner/admin --------------------------------------------------------

    public function test_a_cashier_never_receives_cost_or_margin(): void
    {
        $this->makeOrder();

        foreach ($this->rows() as $row) {
            foreach (['cost_total', 'margin_amount', 'margin_percent'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
        }
    }

    public function test_owner_sees_margin_for_orders(): void
    {
        $order = $this->makeOrder(); // total 78.000, modal 3×4.000 + 20.000 = 32.000 → margin 46.000
        $this->actingAs($this->owner, 'sanctum');

        $row = $this->rows()->firstWhere('key', "order:{$order->id}");

        $this->assertSame('32000.00', $row['cost_total']);
        $this->assertSame('46000.00', $row['margin_amount']);
        $this->assertEquals(59.0, $row['margin_percent']);
    }
}
