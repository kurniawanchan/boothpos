<?php

namespace Tests\Feature;

use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Support\BusinessDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US6 (SC-011) — kiriman yang belum/tidak pernah dikirim ke dapur TIDAK boleh muncul di angka mana pun.
 */
class SelfServiceReportIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
    }

    private function salesTotal(): float
    {
        $today = BusinessDay::today();

        return (float) $this->getJson("/api/v1/reports/sales?date_from={$today}&date_to={$today}")->assertOk()->json('totals.grand_total');
    }

    public function test_waiting_verified_and_rejected_submissions_are_invisible_to_every_figure(): void
    {
        $item = $this->makeMenuItem(stock: 10, price: 30000);
        $waiting = $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => 1]]);
        $verified = $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => 1]]);
        $rejected = $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => 1]]);
        $owner = $this->ssOwner();
        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$verified->id}/verify", ['reference' => 'BCA-1234'])->assertOk();
        $this->postJson("/api/v1/self-service/orders/{$rejected->id}/reject", ['reason' => 'palsu'])->assertOk();

        $this->assertSame(0.0, $this->salesTotal());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, KitchenTicket::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame(10, (int) $item->fresh()->current_stock);

        $today = BusinessDay::today();
        $this->getJson("/api/v1/dashboard/summary?date_from={$today}&date_to={$today}")->assertOk();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertNotNull($waiting->fresh());
    }

    public function test_only_after_dispatch_does_the_sale_count(): void
    {
        $item = $this->makeMenuItem(stock: 10, price: 30000);
        $placed = $this->ssPlaceOrder([['variant_id' => $item->id, 'qty' => 2]]);
        $owner = $this->ssOwner();
        $this->makeShift($owner);
        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$placed->id}/verify", ['reference' => 'BCA-1234'])->assertOk();
        $this->assertSame(0.0, $this->salesTotal());

        $this->postJson("/api/v1/self-service/orders/{$placed->id}/dispatch")->assertOk();

        $this->assertSame(60000.0, $this->salesTotal());
    }
}
