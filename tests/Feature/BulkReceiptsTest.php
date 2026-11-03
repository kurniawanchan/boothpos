<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 054 (US9) — POST /orders/bulk-receipts: isi struk banyak penjualan untuk unduhan massal. Dikunci: isinya
 * IDENTIK dengan struk satuan (satu perakit), batas 200, id tak dikenal dilaporkan, urutan permintaan
 * dipertahankan, batal ikut dengan statusnya, tanpa N+1, dan tanpa data pelanggan di luar struk satuan.
 */
class BulkReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private CashierSession $session;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'BRC0001', 'sell_price' => 25000, 'cost_price' => 1, 'current_stock' => 1000]);
        $this->actingAs($this->cashier, 'sanctum');
    }

    private function sale(array $extra = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $extra), $this->cashier);
    }

    private function bulk(array $ids)
    {
        return $this->postJson('/api/v1/orders/bulk-receipts', ['order_ids' => $ids]);
    }

    public function test_each_receipt_is_identical_to_the_single_receipt(): void
    {
        $a = $this->sale();
        $b = $this->sale(['customer_id' => Customer::factory()->create()->id]);

        $data = $this->bulk([$a->id, $b->id])->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame($this->getJson("/api/v1/orders/{$a->id}/receipt")->json(), $data[0]);
        $this->assertSame($this->getJson("/api/v1/orders/{$b->id}/receipt")->json(), $data[1]);
    }

    public function test_the_requested_order_is_kept_and_duplicates_collapse(): void
    {
        $a = $this->sale();
        $b = $this->sale();

        $numbers = collect($this->bulk([$b->id, $a->id, $b->id])->json('data'))->pluck('order_number')->all();

        $this->assertSame([$b->order_number, $a->order_number], $numbers);
    }

    public function test_unknown_and_other_mode_ids_are_reported_not_invented(): void
    {
        $ok = $this->sale();
        $demo = $this->sale();
        DB::table('orders')->where('id', $demo->id)->update(['data_mode' => 'demo']);

        $res = $this->bulk([$ok->id, $demo->id, 999999])->assertOk()->json();

        $this->assertCount(1, $res['data']);
        $this->assertEqualsCanonicalizing([$demo->id, 999999], $res['missing']);
    }

    public function test_more_than_200_ids_or_none_is_refused(): void
    {
        $this->bulk(range(1, 201))->assertStatus(422);
        $this->postJson('/api/v1/orders/bulk-receipts', ['order_ids' => []])->assertStatus(422);
        $this->postJson('/api/v1/orders/bulk-receipts', [])->assertStatus(422);
        $this->bulk(['abc'])->assertStatus(422);
    }

    public function test_a_voided_sale_is_returned_with_its_status(): void
    {
        $order = $this->sale();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum')->postJson("/api/v1/orders/{$order->id}/void", ['reason' => 'salah'])->assertOk();

        $this->assertSame('voided', $this->bulk([$order->id])->json('data.0.status'));
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/orders/bulk-receipts', ['order_ids' => [1]])->assertStatus(401);
    }

    public function test_the_payload_has_no_customer_data_beyond_the_single_receipt(): void
    {
        $order = $this->sale(['customer_id' => Customer::factory()->create()->id]);

        $bulkKeys = array_keys($this->bulk([$order->id])->json('data.0'));
        $singleKeys = array_keys($this->getJson("/api/v1/orders/{$order->id}/receipt")->json());

        $this->assertSame($singleKeys, $bulkKeys);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_orders(): void
    {
        // Pelanggan juga ada di pesanan pembanding: eager-load pelanggan adalah SATU query tetap yang hanya jalan bila ada pelanggan.
        $first = $this->sale(['customer_id' => Customer::factory()->create()->id]);
        $this->bulk([$first->id]); // pemanasan cache pengaturan/lisensi
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->bulk([$first->id]);
        $few = count(DB::getQueryLog());

        $ids = [$first->id];
        foreach (range(1, 10) as $i) {
            $ids[] = $this->sale(['customer_id' => Customer::factory()->create()->id])->id;
        }
        DB::flushQueryLog();
        $this->bulk($ids)->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()));
    }
}
