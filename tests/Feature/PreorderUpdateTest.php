<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 022-preorder-invoice-crud-overhaul (US1, FR-001/FR-001a/FR-002) —
 * mirrors tests/Feature/PreorderTest.php's setup.
 */
class PreorderUpdateTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variantA;
    private ProductVariant $variantB;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variantA = $product->variants()->create([
            'sku' => 'UPDA0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->variantB = $product->variants()->create([
            'sku' => 'UPDB0001', 'sell_price' => 200000, 'cost_price' => 100000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(array $overrides = []): array
    {
        return $this->postJson('/api/v1/preorders', array_merge([
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 2]],
        ], $overrides))->assertCreated()->json();
    }

    private function moveToStatus(int $preorderId, string $status): TestResponse
    {
        return $this->patchJson("/api/v1/preorders/{$preorderId}/status", ['status' => $status]);
    }

    public function test_editing_items_before_arrived_records_no_stock_movement(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 5]],
        ]);

        $response->assertOk();
        $this->assertEquals('500000.00', $response->json('subtotal'));
        $this->assertSame(0, $this->variantA->fresh()->current_stock);
        $this->assertDatabaseMissing('stock_movements', ['variant_id' => $this->variantA->id]);
    }

    public function test_editing_items_after_arrived_corrects_stock_by_exact_delta(): void
    {
        $preorder = $this->createPreorder(); // qty 2 of variantA
        $this->moveToStatus($preorder['id'], 'dp_paid')->assertOk();
        $this->moveToStatus($preorder['id'], 'arrived')->assertOk();
        $this->assertSame(2, $this->variantA->fresh()->current_stock);

        // Increase variantA to 5 and add variantB qty 3.
        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [
                ['variant_id' => $this->variantA->id, 'qty' => 5],
                ['variant_id' => $this->variantB->id, 'qty' => 3],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(5, $this->variantA->fresh()->current_stock);
        $this->assertSame(3, $this->variantB->fresh()->current_stock);
        // Delta for variantA was +3 (5-2), never a reversal of the original +2.
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variantA->id, 'type' => 'purchase', 'qty_change' => 3]);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variantB->id, 'type' => 'purchase', 'qty_change' => 3]);
        // Only one delta movement was written for variantA beyond the original arrival movement.
        $this->assertEquals(2, \DB::table('stock_movements')->where('variant_id', $this->variantA->id)->count());
    }

    public function test_editing_items_after_arrived_with_unchanged_quantity_writes_no_new_movement(): void
    {
        $preorder = $this->createPreorder();
        $this->moveToStatus($preorder['id'], 'dp_paid')->assertOk();
        $this->moveToStatus($preorder['id'], 'arrived')->assertOk();

        $movementCountBefore = \DB::table('stock_movements')->where('variant_id', $this->variantA->id)->count();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 2]],
            'notes' => 'catatan diperbarui',
        ]);

        $response->assertOk();
        $this->assertEquals(
            $movementCountBefore,
            \DB::table('stock_movements')->where('variant_id', $this->variantA->id)->count()
        );
    }

    public function test_edit_is_refused_when_status_is_handed_over(): void
    {
        $preorder = $this->createPreorder();
        $this->moveToStatus($preorder['id'], 'dp_paid')->assertOk();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 200000, 'purpose' => 'settlement',
        ])->assertCreated();
        $this->moveToStatus($preorder['id'], 'arrived')->assertOk();
        $this->moveToStatus($preorder['id'], 'settled')->assertOk();
        $this->moveToStatus($preorder['id'], 'handed_over')->assertOk();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 1]],
        ]);

        $response->assertStatus(409);
    }

    public function test_edit_is_refused_when_status_is_cancelled(): void
    {
        $preorder = $this->createPreorder();
        $this->moveToStatus($preorder['id'], 'cancelled')->assertOk();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 1]],
        ]);

        $response->assertStatus(409);
    }

    public function test_edit_still_enforces_discount_and_pickup_day_cross_field_rules(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 1]],
            'discount' => 999999,
        ]);

        $response->assertStatus(409)->assertJsonValidationErrors('discount');
    }
}
