<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_artist_settlement_matches_sum_of_actual_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $artistA = Artist::factory()->create(['code' => 'AAA']);
        $artistB = Artist::factory()->create(['code' => 'BBB']);
        $category = Category::factory()->create();

        $productA = Product::factory()->create(['artist_id' => $artistA->id, 'category_id' => $category->id]);
        $variantA = $productA->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $productB = Product::factory()->create(['artist_id' => $artistB->id, 'category_id' => $category->id]);
        $variantB = $productB->variants()->create(['sku' => 'BBBKYBBB0001', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);
        $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantA->id, 'qty' => 3], ['variant_id' => $variantB->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $cashier);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}");

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('artist_id');

        $this->assertEquals('30000.00', $rows[$artistA->id]['total_sales']); // 3 x 10000
        $this->assertEquals('20000.00', $rows[$artistB->id]['total_sales']); // 1 x 20000
        $this->assertEquals(3, $rows[$artistA->id]['total_units']);
    }

    public function test_settlement_recalculates_live_after_a_void(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => 'AAAKYAAA0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);

        $orderService = app(OrderService::class);
        $order = $orderService->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $this->actingAs($owner, 'sanctum');

        $before = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data.0.total_sales');
        $this->assertEquals('20000.00', $before);

        $orderService->void($order, 'Test batal', $owner);

        $after = $this->getJson("/api/v1/reports/artist-settlements?event_id={$event->id}")->json('data.0.total_sales');
        $this->assertEquals('0.00', $after); // order voided -> tidak ikut terhitung
    }

    public function test_profit_report_requires_owner_or_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $event = Event::factory()->create();

        $this->getJson("/api/v1/reports/profit?event_id={$event->id}")->assertStatus(403);
    }
}
