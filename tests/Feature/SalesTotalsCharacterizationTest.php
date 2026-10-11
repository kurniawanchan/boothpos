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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T003) — karakterisasi angka `pos_*` dari GET /reports/sales
 * SEBELUM agregasinya dipindah ke App\Services\SalesFigures.
 *
 * Test ini mengunci perilaku YANG BERLAKU HARI INI (bukan perilaku ideal): hanya order
 * `completed`, net = SUM(line_total) tanpa memotong diskon tingkat-order, bruto = net + diskon
 * item, pre-order hanya masuk ke total bersama (bukan `pos_*`). Bila refaktor mengubah satu
 * angka di sini, itu regresi — bukan "perbaikan".
 */
class SalesTotalsCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    private CashierSession $session;

    private $variantA;

    private $variantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');

        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->owner->id, 'status' => 'open']);

        $category = Category::factory()->create();
        $artist = Artist::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $this->variantA = $product->variants()->create(['sku' => 'CHRA0001', 'variant_name' => 'A', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 500]);
        $this->variantB = $product->variants()->create(['sku' => 'CHRB0001', 'variant_name' => 'B', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 500]);
    }

    private function order(array $items, array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => $items,
            'payments' => [['method' => 'cash', 'amount' => 500000]],
        ], $overrides), $this->owner);
    }

    private function sales(string $query = ''): array
    {
        return $this->getJson('/api/v1/reports/sales?group_by=day'.$query)->assertOk()->json('totals');
    }

    public function test_pos_totals_count_completed_orders_only_and_net_is_the_sum_of_line_totals(): void
    {
        $this->order([['variant_id' => $this->variantA->id, 'qty' => 3]]); // 30.000
        $this->order([['variant_id' => $this->variantB->id, 'qty' => 1, 'discount_amount' => 2000]]); // net 18.000, bruto 20.000
        $voided = $this->order([['variant_id' => $this->variantA->id, 'qty' => 5]]);
        app(OrderService::class)->void($voided, 'salah input', $this->owner);

        $totals = $this->sales();

        $this->assertSame(2, (int) $totals['order_count']);
        $this->assertEquals(4, $totals['pos_unit_count']);
        $this->assertSame('48000.00', $totals['pos_net_sales']);
        $this->assertSame('50000.00', $totals['pos_gross_sales']);
    }

    public function test_an_order_level_discount_is_not_deducted_from_pos_net_sales(): void
    {
        // Perilaku yang diwarisi (keputusan pemilik O3): halaman Sales mengikuti SUM(line_total).
        $this->order([['variant_id' => $this->variantA->id, 'qty' => 2]], ['discount_amount' => 5000]);

        $totals = $this->sales();

        $this->assertSame('20000.00', $totals['pos_net_sales']);
    }

    public function test_preorder_revenue_joins_the_shared_totals_but_never_the_pos_totals(): void
    {
        $this->order([['variant_id' => $this->variantA->id, 'qty' => 1]]); // 10.000

        $service = app(\App\Services\PreorderService::class);
        $preorder = $service->create([
            'event_id' => $this->event->id,
            'customer_id' => Customer::factory()->create()->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variantB->id, 'qty' => 1]],
        ], $this->owner);
        $service->recordPayment($preorder, ['method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => 20000]);

        $totals = $this->sales();

        $this->assertSame('10000.00', $totals['pos_net_sales']);
        $this->assertSame('30000.00', $totals['net_sales']);
        $this->assertEquals(1, $totals['pos_unit_count']);
    }

    public function test_date_filters_follow_the_stored_business_date(): void
    {
        $old = $this->order([['variant_id' => $this->variantA->id, 'qty' => 1]]);
        // 042: tanggal laporan kini TANGGAL BISNIS tersimpan (bukan DATE(created_at) UTC), jadi baris lama
        // dipindah mundur dengan menggeser kedua kolomnya — sisa tes ini tidak berubah.
        $old->forceFill(['created_at' => now()->subDays(3), 'business_date' => now()->subDays(3)->toDateString(), 'order_number' => 'TRX-OLD-0001'])->save();
        $this->order([['variant_id' => $this->variantB->id, 'qty' => 1]]);

        $today = \App\Support\BusinessDay::today();
        $totals = $this->sales("&date_from={$today}&date_to={$today}");

        $this->assertSame('20000.00', $totals['pos_net_sales']);
        $this->assertSame(1, (int) $totals['order_count']);
    }
}
