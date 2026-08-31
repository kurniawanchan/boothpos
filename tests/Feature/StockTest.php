<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private function makeVariant(int $stock = 10): ProductVariant
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        return $product->variants()->create([
            'sku' => 'AAABBCCC0001', 'sell_price' => 10000, 'current_stock' => $stock, 'low_stock_alert' => 5,
        ]);
    }

    public function test_stock_service_increases_and_decreases_correctly(): void
    {
        $variant = $this->makeVariant(10);
        $service = app(StockService::class);

        $service->applyMovement($variant, 'purchase', 5);
        $this->assertSame(15, $variant->fresh()->current_stock);

        $service->applyMovement($variant, 'sale', -3);
        $this->assertSame(12, $variant->fresh()->current_stock);
    }

    public function test_stock_service_rejects_movement_that_makes_stock_negative(): void
    {
        $variant = $this->makeVariant(2);
        $service = app(StockService::class);

        $this->expectException(ValidationException::class);
        $service->applyMovement($variant, 'sale', -5);

        // Stok tidak boleh berubah sama sekali saat ditolak.
        $this->assertSame(2, $variant->fresh()->current_stock);
    }

    public function test_every_movement_writes_an_audit_row(): void
    {
        $variant = $this->makeVariant(10);
        app(StockService::class)->applyMovement($variant, 'adjustment', 3, reason: 'Koreksi stok awal');

        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id, 'type' => 'adjustment',
            'qty_change' => 3, 'stock_before' => 10, 'stock_after' => 13,
        ]);
    }

    public function test_manual_adjustment_endpoint_requires_reason(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');
        $variant = $this->makeVariant(10);

        $response = $this->postJson('/api/v1/stock/adjustments', [
            'items' => [['variant_id' => $variant->id, 'qty_change' => 5]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_cashier_cannot_perform_manual_adjustment(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        $variant = $this->makeVariant(10);

        $this->postJson('/api/v1/stock/adjustments', [
            'reason' => 'Test', 'items' => [['variant_id' => $variant->id, 'qty_change' => 5]],
        ])->assertStatus(403);
    }

    public function test_low_stock_endpoint_only_returns_variants_at_or_below_threshold(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $low = $this->makeVariant(3); // alert=5, stock=3 -> low
        $ok = $this->makeVariant(20); // alert=5, stock=20 -> not low

        $response = $this->getJson('/api/v1/stock/low');

        $ids = collect($response->json('data'))->pluck('variant_id');
        $this->assertTrue($ids->contains($low->id));
        $this->assertFalse($ids->contains($ok->id));
    }
}
