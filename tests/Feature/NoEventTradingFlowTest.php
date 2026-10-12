<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 046-hide-events-preorders (US1) — seluruh alur dagang (buka shift → jual → bayar → struk → tutup shift)
 * berjalan pada instalasi tanpa satu pun event, dan tidak satu respons pun membawa event.
 */
class NoEventTradingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function assertNoEventKeys(array $payload, string $where): void
    {
        foreach (array_keys($payload) as $key) {
            $this->assertStringNotContainsString('event', (string) $key, "{$where} masih membawa {$key}");
        }
    }

    public function test_a_cashier_can_open_sell_receipt_and_close_without_any_event(): void
    {
        $this->assertSame(0, DB::table('events')->count());
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $outlet = Outlet::factory()->create();
        $product = Product::factory()->create(['home_outlet_id' => $outlet->id, 'category_id' => Category::factory()->create()->id]);
        $variant = $product->variants()->create(['sku' => 'NOEKYAAA0001', 'sell_price' => 15000, 'cost_price' => 6000, 'current_stock' => 50]);

        $session = $this->postJson('/api/v1/sessions', ['opening_cash' => 100000])->assertCreated();
        $this->assertNoEventKeys($session->json(), 'shift');
        $sessionId = $session->json('id');

        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $sessionId,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ])->assertCreated();
        $this->assertNoEventKeys($order->json(), 'order');

        $receipt = $this->getJson('/api/v1/orders/'.$order->json('id').'/receipt')->assertOk();
        $this->assertNoEventKeys($receipt->json(), 'struk');

        $summary = $this->getJson("/api/v1/sessions/{$sessionId}/summary")->assertOk();
        $this->assertNoEventKeys($summary->json(), 'ringkasan shift');

        $this->postJson("/api/v1/sessions/{$sessionId}/close", ['closing_cash' => 130000])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_difference', '0.00');

        $this->assertNull(DB::table('orders')->value('event_id'));
        $this->assertNull(DB::table('cashier_sessions')->value('event_id'));
    }

    public function test_reports_and_the_sales_list_work_without_events(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        foreach (['sales', 'profit', 'outlet-settlements', 'outlet-profit'] as $report) {
            $json = $this->getJson("/api/v1/reports/{$report}")->assertOk()->json();
            $this->assertNoEventKeys($json, $report);
        }
    }
}
