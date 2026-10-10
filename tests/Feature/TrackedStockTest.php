<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 — research.md Keputusan 4: StockService::applyMovementIfTracked()
 * mendelegasikan ke applyMovement() untuk varian yang stoknya dilacak dan
 * tidak melakukan apa pun untuk hidangan made-to-order (track_stock=false).
 */
class TrackedStockTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    public function test_tracked_variant_goes_through_the_regular_movement_path(): void
    {
        $variant = $this->makeVariant(stock: 10);

        $movement = app(StockService::class)->applyMovementIfTracked($variant, 'adjustment', -3, reason: 'uji');

        $this->assertNotNull($movement);
        $this->assertSame(7, $variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'qty_change' => -3, 'stock_after' => 7]);
    }

    public function test_tracked_variant_still_refuses_to_go_negative(): void
    {
        $variant = $this->makeVariant(stock: 2);

        $this->expectException(ValidationException::class);
        try {
            app(StockService::class)->applyMovementIfTracked($variant, 'sale', -5);
        } finally {
            $this->assertSame(2, $variant->fresh()->current_stock);
            $this->assertDatabaseMissing('stock_movements', ['variant_id' => $variant->id, 'type' => 'sale']);
        }
    }

    public function test_untracked_variant_is_a_no_op(): void
    {
        $variant = $this->makeVariant(stock: 0, variantOverrides: ['track_stock' => false]);

        $result = app(StockService::class)->applyMovementIfTracked($variant, 'sale', -4);

        $this->assertNull($result);
        $this->assertSame(0, $variant->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_track_stock_defaults_to_true_for_existing_style_variants(): void
    {
        $this->assertTrue((bool) $this->makeVariant()->fresh()->track_stock);
    }

    public function test_retail_sale_and_void_of_an_untracked_variant_never_touch_stock(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $session = $this->makeShift($cashier);
        $variant = $this->makeVariant(stock: 0, price: 20000, variantOverrides: ['track_stock' => false]);

        $this->actingAs($cashier, 'sanctum');
        $orderId = $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => $this->newLocalRef(),
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 40000]],
        ])->assertCreated()->json('id');

        $this->assertSame(0, $variant->fresh()->current_stock);

        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$orderId}/void", ['reason' => 'uji'])->assertOk();

        $this->assertSame('voided', Order::find($orderId)->status);
        $this->assertSame(0, $variant->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
