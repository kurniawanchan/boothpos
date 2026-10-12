<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 2 — shift dan struk menyebut outlet tempat penjualan terjadi.
 */
class ShiftOutletPayloadTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    public function test_the_current_shift_and_its_summary_name_the_outlet_and_the_receipt_prints_it(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$a, $b]);
        $product = Product::factory()->create(['home_outlet_id' => $a->id, 'category_id' => Category::factory()->create()->id]);
        $variant = $product->variants()->create(['sku' => 'PAY00001', 'variant_name' => 'Regular', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);
        $shift = $this->shiftAt($b, $cashier);
        $order = $this->orderAt($shift, $variant);
        $this->actingAs($cashier, 'sanctum');

        $this->getJson('/api/v1/sessions/current')->assertOk()->assertJsonPath('outlet.name', $b->name)->assertJsonPath('outlet.code', $b->code);
        $this->getJson("/api/v1/sessions/{$shift->id}/summary")->assertOk()->assertJsonPath('session.outlet.name', $b->name);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('outlet.name', $b->name);
        $this->assertContains($b->name, collect($this->getJson('/api/v1/reports/sales?event_id='.$shift->event_id)->assertOk()->json('transactions'))->pluck('sold_at_name')->all());
        $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk()->assertJsonPath('outlet_name', $b->name)->assertJsonPath('outlet_code', $b->code);
    }
}
