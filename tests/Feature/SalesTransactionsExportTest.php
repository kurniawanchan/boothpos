<?php

namespace Tests\Feature;

use App\Exports\GenericArrayExport;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Ekspor "apa yang tampil di layar": klien hanya mengirim KUNCI baris ("order:12",
 * "preorder:3"); isi berkas dibangun ulang di server lewat service yang sama dengan
 * daftar, jadi nominal tak bisa dipalsukan dan berkas tak pernah berbeda dari layar.
 */
class SalesTransactionsExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/reports/sales/transactions/export';

    private User $cashier;

    private User $owner;

    private Event $event;

    private CashierSession $session;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');

        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create(['name' => 'Nekoyama Studio'])->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Akatsuki Keychain', 'is_preorder' => true]);
        $this->variant = $product->variants()->create(['sku' => 'NEKKYAKT0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => Customer::factory()->create(['name' => 'Budi', 'phone' => '0812-9999-0000', 'email' => 'budi@contoh.test'])->id,
            'items' => [['variant_id' => $this->variant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ], $overrides), $this->cashier);
    }

    /** Jalankan ekspor dan kembalikan baris-baris yang DITULIS ke berkas. */
    private function exported(array $body = [], ?User $as = null): array
    {
        Excel::fake();
        if ($as) {
            $this->actingAs($as, 'sanctum');
        }
        $this->postJson(self::URL, array_merge(['event_id' => $this->event->id], $body))->assertOk();

        $rows = [];
        Excel::assertDownloaded('transaksi-penjualan.xlsx', function (GenericArrayExport $export) use (&$rows) {
            $rows = $export->array();

            return true;
        });

        return $rows;
    }

    public function test_it_exports_the_whole_event_when_no_keys_are_given(): void
    {
        $this->makeOrder();
        $this->makeOrder();

        $this->assertCount(2, $this->exported());
    }

    public function test_only_the_requested_rows_are_exported(): void
    {
        $keep = $this->makeOrder();
        $this->makeOrder(); // tidak diminta

        $rows = $this->exported(['keys' => ["order:{$keep->id}"]]);

        $this->assertCount(1, $rows);
        $this->assertSame($keep->order_number, $rows[0]['transaction_no']);
    }

    public function test_rows_have_readable_columns_with_numeric_money(): void
    {
        $order = $this->makeOrder();

        $row = $this->exported(['keys' => ["order:{$order->id}"]])[0];

        $this->assertSame(
            ['transaction_no', 'status', 'time', 'customer', 'sellers', 'cashier', 'items', 'units', 'discount', 'payment_methods', 'payment_status', 'cash', 'non_cash', 'total'],
            array_keys($row),
        );
        $this->assertSame('Budi', $row['customer']);
        $this->assertSame('Nekoyama Studio', $row['sellers']);
        $this->assertSame('Kasir Satu', $row['cashier']);
        $this->assertSame('cash', $row['payment_methods']);
        $this->assertSame(3.0, (float) $row['units']);
        $this->assertSame(30000.0, $row['total']);   // angka, bukan string — bisa dijumlah di Excel
        $this->assertSame(30000.0, $row['cash']);
    }

    public function test_every_line_is_listed_not_just_the_two_shown_in_the_preview(): void
    {
        $extra = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Poster'])
            ->variants()->create(['sku' => 'POSTER0001', 'sell_price' => 5000, 'cost_price' => 1000, 'current_stock' => 50]);
        $third = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Pin'])
            ->variants()->create(['sku' => 'PIN0001', 'sell_price' => 2000, 'cost_price' => 500, 'current_stock' => 50]);
        $order = $this->makeOrder(['items' => [
            ['variant_id' => $this->variant->id, 'qty' => 3], ['variant_id' => $extra->id, 'qty' => 1], ['variant_id' => $third->id, 'qty' => 2],
        ], 'payments' => [['method' => 'cash', 'amount' => 40000]]]);

        $items = $this->exported(['keys' => ["order:{$order->id}"]])[0]['items'];

        $this->assertStringContainsString('Akatsuki Keychain', $items);
        $this->assertStringContainsString('Poster', $items);
        $this->assertStringContainsString('Pin', $items);   // baris ke-3 ikut, tidak dipotong
        $this->assertStringContainsString('×3', $items);
    }

    public function test_customer_contact_details_never_leave_in_the_file(): void
    {
        $this->makeOrder();

        $blob = json_encode($this->exported());

        $this->assertStringNotContainsString('0812-9999-0000', $blob);
        $this->assertStringNotContainsString('budi@contoh.test', $blob);
    }

    public function test_a_cashier_gets_no_cost_or_margin_but_an_owner_does(): void
    {
        $order = $this->makeOrder();

        $cashierRow = $this->exported(['keys' => ["order:{$order->id}"]])[0];
        $this->assertArrayNotHasKey('cost', $cashierRow);
        $this->assertArrayNotHasKey('margin', $cashierRow);

        $ownerRow = $this->exported(['keys' => ["order:{$order->id}"]], $this->owner)[0];
        $this->assertSame(12000.0, $ownerRow['cost']);     // 3 × 4.000
        $this->assertSame(18000.0, $ownerRow['margin']);   // 30.000 − 12.000
    }

    public function test_keys_that_do_not_exist_are_ignored_instead_of_inventing_rows(): void
    {
        $order = $this->makeOrder();

        $rows = $this->exported(['keys' => ["order:{$order->id}", 'order:999999']]);

        $this->assertCount(1, $rows);
    }

    public function test_pre_order_keys_are_not_accepted_because_sales_is_pos_only(): void
    {
        $this->makeOrder();

        $this->postJson(self::URL, ['keys' => ['preorder:1']])->assertStatus(422);
    }

    public function test_keys_from_another_event_are_not_leaked_when_an_event_filter_is_given(): void
    {
        $mine = $this->makeOrder();
        $otherEvent = Event::factory()->create(['status' => 'active']);
        $otherSession = CashierSession::factory()->create(['event_id' => $otherEvent->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $foreign = $this->makeOrder(['session_id' => $otherSession->id]);

        $rows = $this->exported(['keys' => ["order:{$mine->id}", "order:{$foreign->id}"]]);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->order_number, $rows[0]['transaction_no']);
    }

    public function test_voided_orders_are_only_exported_when_asked_for(): void
    {
        $this->makeOrder();
        $voided = $this->makeOrder();
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$voided->id}/void", ['reason' => 'Salah input'])->assertOk();

        $this->assertCount(1, $this->exported());
        $rows = $this->exported(['include_voided' => true]);
        $this->assertCount(2, $rows);
        $this->assertContains('voided', array_column($rows, 'status'));
    }

    public function test_malformed_keys_and_oversized_requests_are_rejected(): void
    {
        $this->postJson(self::URL, ['keys' => ['drop table']])->assertStatus(422);
        $this->postJson(self::URL, ['keys' => ['order:abc']])->assertStatus(422);
        $this->postJson(self::URL, ['keys' => ['preorder:12']])->assertStatus(422);
        $this->postJson(self::URL, ['keys' => array_fill(0, 5001, 'order:1')])->assertStatus(422);
    }

    public function test_it_requires_a_login(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson(self::URL, [])->assertStatus(401);
    }
}
