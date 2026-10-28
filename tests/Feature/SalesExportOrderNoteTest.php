<?php

namespace Tests\Feature;

use App\Exports\GenericArrayExport;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SalesTransactionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** 078 / US4 — transaksi penjualan membawa catatan order: baris daftar (`notes`) dan kolom ekspor `order_note` (setelah `items`). */
class SalesExportOrderNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private CashierSession $session;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $this->session = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open']);
        $product = Product::factory()->create(['home_outlet_id' => Outlet::factory()->create()->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Kopi']);
        $this->variant = $product->variants()->create(['sku' => 'KOPI0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
    }

    private function makeOrder(?string $note): Order
    {
        $order = app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => Customer::factory()->create()->id,
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->cashier);
        $order->forceFill(['notes' => $note])->save();

        return $order;
    }

    public function test_the_service_rows_carry_the_note(): void
    {
        $with = $this->makeOrder('Bungkus terpisah');
        $without = $this->makeOrder(null);

        $rows = app(SalesTransactionsService::class)->build([], auth()->user(), detail: true)['transactions']->keyBy('id');

        $this->assertSame('Bungkus terpisah', $rows[$with->id]['notes']);
        $this->assertNull($rows[$without->id]['notes']);
    }

    public function test_the_export_has_an_order_note_column_right_after_items(): void
    {
        $with = $this->makeOrder('Bungkus terpisah');
        $without = $this->makeOrder(null);

        Excel::fake();
        $this->postJson('/api/v1/reports/sales/transactions/export', ['keys' => ["order:{$with->id}", "order:{$without->id}"]])->assertOk();
        $rows = [];
        Excel::assertDownloaded('transaksi-penjualan.xlsx', function (GenericArrayExport $export) use (&$rows) {
            $rows = $export->array();

            return true;
        });

        $keys = array_keys($rows[0]);
        $this->assertSame('order_note', $keys[array_search('items', $keys, true) + 1]);
        $byNo = collect($rows)->keyBy('transaction_no');
        $this->assertSame('Bungkus terpisah', $byNo[$with->order_number]['order_note']);
        $this->assertTrue(in_array($byNo[$without->order_number]['order_note'], [null, ''], true), 'tanpa catatan → kolom kosong');
    }
}
