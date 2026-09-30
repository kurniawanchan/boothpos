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
 * Data untuk halaman Sales (filter, urutan kolom, dan tampilan item yang lebih
 * informatif). Halaman ini terbuka untuk SEMUA peran termasuk kasir, jadi selain
 * memastikan field baru ada, test ini juga menjaga agar modal/untung TIDAK bocor.
 */
class SalesPageDataTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Event $event;

    private CashierSession $session;

    private $variantA;

    private $variantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->actingAs($this->cashier, 'sanctum');

        $this->event = Event::factory()->create(['status' => 'active', 'name' => 'Sakana Fest']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);

        $category = Category::factory()->create(['name' => 'Keychain']);
        $artistA = Artist::factory()->create(['name' => 'Nekoyama Studio']);
        $artistB = Artist::factory()->create(['name' => 'Yukishiro Works']);
        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id, 'name' => 'Akatsuki Keychain']);
        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id, 'name' => 'Sakura Sticker']);
        $this->variantA = $productA->variants()->create(['sku' => 'NEKKYAKT0001', 'variant_name' => 'Blue', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
        $this->variantB = $productB->variants()->create(['sku' => 'YUKSTSAK0001', 'variant_name' => 'Pink', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 100]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [
                ['variant_id' => $this->variantA->id, 'qty' => 3],
                ['variant_id' => $this->variantB->id, 'qty' => 1, 'discount_amount' => 2000],
            ],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $overrides), $this->cashier);
    }

    private function transaction(Order $order): array
    {
        $rows = $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk()->json('transactions');

        return collect($rows)->firstWhere('id', $order->id);
    }

    // --- baris daftar transaksi ------------------------------------------------

    public function test_transaction_rows_carry_the_fields_the_sales_filters_and_sorting_need(): void
    {
        $order = $this->makeOrder();

        $row = $this->transaction($order);

        $this->assertSame($this->cashier->id, $row['cashier_id']);
        $this->assertEquals(4, $row['unit_count']);                 // 3 + 1 unit (bukan jumlah baris item)
        $this->assertSame(2, $row['item_count']);                   // jumlah baris item tetap ada
        $this->assertSame('2000.00', $row['discount_amount']);      // diskon per item (diteruskan ke total pesanan)
        $this->assertSame(['cash'], $row['payment_methods']);
        $this->assertSame('offline', $row['channel']);
    }

    public function test_row_discount_is_item_discounts_plus_the_order_level_discount(): void
    {
        $order = $this->makeOrder(['discount_amount' => 500]); // + diskon item 2000 dari makeOrder()

        $this->assertSame('2500.00', $this->transaction($order)['discount_amount']);
    }

    public function test_payment_methods_are_unique_and_list_every_method_used(): void
    {
        $order = $this->makeOrder();
        // Aturan DB (chk_payments_channel): pembayaran non-tunai WAJIB punya kanal.
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet', 'provider' => 'GoPay']);
        $order->payments()->create(['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'purpose' => 'full', 'amount' => 1000, 'verification' => 'verified', 'paid_at' => now()]);
        $order->payments()->create(['method' => 'cash', 'purpose' => 'full', 'amount' => 500, 'verification' => 'verified', 'paid_at' => now()]);

        $methods = $this->transaction($order)['payment_methods'];

        $this->assertEqualsCanonicalizing(['cash', 'qr_ewallet'], $methods);
        $this->assertCount(2, $methods); // 'cash' dua kali → tetap satu
    }

    public function test_transaction_rows_never_expose_cost_or_profit_because_cashiers_can_open_this_page(): void
    {
        $row = $this->transaction($this->makeOrder());

        foreach (['total_cost', 'cost_price', 'profit', 'margin'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function test_a_walk_in_order_still_has_all_new_fields(): void
    {
        $row = $this->transaction($this->makeOrder());

        $this->assertNull($row['customer_name']);
        foreach (['cashier_id', 'unit_count', 'discount_amount', 'payment_methods', 'channel'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    // --- detail satu transaksi (tampilan item) ----------------------------------

    public function test_order_detail_describes_who_where_and_when(): void
    {
        $customer = Customer::factory()->create(['name' => 'Budi Santoso', 'phone' => '0812-0000-1111', 'email' => 'budi@contoh.test']);
        $order = $this->makeOrder(['customer_id' => $customer->id, 'notes' => 'Ambil jam 3']);

        $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json();

        $this->assertSame('Budi Santoso', $detail['customer']['name']);
        $this->assertSame('0812-0000-1111', $detail['customer']['phone']);
        $this->assertSame('Kasir Satu', $detail['cashier']['name']);
        $this->assertSame('Sakana Fest', $detail['event']['name']);
        $this->assertSame('Ambil jam 3', $detail['notes']);
        $this->assertSame('offline', $detail['channel']);
    }

    public function test_order_detail_customer_is_null_for_walk_in(): void
    {
        $order = $this->makeOrder();

        $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json();

        // Kuncinya HARUS ada (bernilai null), bukan sekadar tidak ada.
        $this->assertArrayHasKey('customer', $detail);
        $this->assertNull($detail['customer']);
    }

    public function test_order_detail_items_are_informative(): void
    {
        $order = $this->makeOrder();

        $items = collect($this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('items'))->keyBy('sku_snapshot');

        $a = $items['NEKKYAKT0001'];
        $this->assertSame('Nekoyama Studio', $a['artist_name']);
        $this->assertSame('Keychain', $a['category_name']);
        $this->assertSame('Blue', $a['variant_name']);
        $this->assertSame('0.00', $a['discount_amount']);
        $this->assertArrayHasKey('image_url', $a);

        $b = $items['YUKSTSAK0001'];
        $this->assertSame('Yukishiro Works', $b['artist_name']);
        $this->assertSame('2000.00', $b['discount_amount']);
        $this->assertSame('18000.00', $b['line_total']); // 20000 - diskon 2000
    }

    public function test_order_detail_payments_say_how_and_when_each_was_paid(): void
    {
        $order = $this->makeOrder();

        $payment = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('payments.0');

        $this->assertSame('cash', $payment['method']);
        $this->assertSame('50000.00', $payment['amount']);
        $this->assertNotEmpty($payment['paid_at']);
        $this->assertArrayHasKey('provider', $payment); // nama kanal (mis. bank/e-wallet); null untuk tunai
    }

    public function test_a_non_cash_payment_shows_which_channel_it_went_through(): void
    {
        $order = $this->makeOrder();
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet', 'provider' => 'GoPay']);
        $order->payments()->create(['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'purpose' => 'full', 'amount' => 1000, 'verification' => 'verified', 'paid_at' => now()]);

        $payments = collect($this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('payments'))->keyBy('method');

        $this->assertSame('GoPay', $payments['qr_ewallet']['provider']);
        $this->assertNull($payments['cash']['provider']);
    }

    public function test_order_detail_never_exposes_cost_or_profit(): void
    {
        $order = $this->makeOrder();

        $detail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json();

        $this->assertArrayNotHasKey('total_cost', $detail);
        foreach ($detail['items'] as $item) {
            $this->assertArrayNotHasKey('cost_price', $item);
        }
    }
}
