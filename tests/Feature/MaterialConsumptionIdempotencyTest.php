<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Services\ModifierMaterialConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 087 US2 (T027) — kirim ulang dan pemanggilan ganda tidak pernah memotong bahan dua kali (FR-013, FR-017).
 */
class MaterialConsumptionIdempotencyTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    public function test_a_retried_checkout_with_the_same_local_ref_deducts_once(): void
    {
        $ref = $this->newLocalRef();
        $first = $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]], $ref)->assertCreated();
        $second = $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]], $ref);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertCount(2, $this->consumptionMovements('sale'));
        $this->assertSame(98.0, $this->balance('telur'));
    }

    public function test_a_retried_settle_and_a_retried_split_deduct_once(): void
    {
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $a = max($this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('soto', 'telur')])->json('lines.*.id'));
        $this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('ayam')])->assertCreated();

        $split = ['client_ref' => $this->newLocalRef(), 'items' => [['item_id' => $a, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 100000]]];
        $this->postJson("/api/v1/tabs/{$tab}/split", $split)->assertCreated();
        $this->postJson("/api/v1/tabs/{$tab}/split", $split)->assertOk()->assertJsonPath('paid_order.id', fn ($id) => $id !== null);
        $this->assertCount(2, $this->consumptionMovements('sale'));

        $ref = $this->newLocalRef();
        $due = (float) Order::find($tab)->total_amount;
        $this->apiPay($tab, [['method' => 'cash', 'amount' => $due]], $ref)->assertOk()->assertJsonPath('status', 'completed');
        $this->apiPay($tab, [['method' => 'cash', 'amount' => $due]], $ref)->assertOk();

        $this->assertCount(3, $this->consumptionMovements('sale'));
        $this->assertSame(49.0, $this->balance('ayam'));
    }

    public function test_calling_consume_twice_on_the_same_order_is_a_no_op(): void
    {
        $id = $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]])->assertCreated()->json('id');
        $order = Order::find($id);
        $cost = (float) $order->total_cost;

        $this->assertSame([], app(ModifierMaterialConsumption::class)->consumeOrder($order, $this->cashier));

        $this->assertCount(2, $this->consumptionMovements('sale'));
        $this->assertSame(99.0, $this->balance('telur'));
        $this->assertEquals($cost, (float) $order->fresh()->total_cost);
    }
}
