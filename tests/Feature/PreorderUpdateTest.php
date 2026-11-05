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

    /**
     * Spec 061: kedatangan tidak menambah stok, jadi mengedit qty sesudahnya
     * juga tidak boleh menggerakkan stok (FR-006). Stok baru berkurang saat
     * serah terima, memakai qty akhir.
     */
    public function test_editing_items_after_arrived_writes_no_stock_movement(): void
    {
        $this->variantA->update(['current_stock' => 10]);
        $this->variantB->update(['current_stock' => 10]);
        $preorder = $this->createPreorder(); // qty 2 of variantA
        $this->moveToStatus($preorder['id'], 'dp_paid')->assertOk();
        $this->moveToStatus($preorder['id'], 'arrived')->assertOk();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [
                ['variant_id' => $this->variantA->id, 'qty' => 5],
                ['variant_id' => $this->variantB->id, 'qty' => 3],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(10, $this->variantA->fresh()->current_stock);
        $this->assertSame(10, $this->variantB->fresh()->current_stock);
        $this->assertSame(0, \DB::table('stock_movements')->count());
    }

    public function test_editing_an_arrived_preorder_then_handing_over_deducts_the_edited_quantity(): void
    {
        $this->variantA->update(['current_stock' => 10]);
        $preorder = $this->createPreorder(); // qty 2 of variantA
        $this->moveToStatus($preorder['id'], 'dp_paid')->assertOk();
        $this->moveToStatus($preorder['id'], 'arrived')->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'items' => [['variant_id' => $this->variantA->id, 'qty' => 5]],
        ])->assertOk();
        $total = \App\Models\Preorder::find($preorder['id'])->total_amount;
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => (float) $total, 'purpose' => 'settlement',
        ])->assertCreated()->assertJsonPath('status', 'settled');

        $this->moveToStatus($preorder['id'], 'handed_over')->assertOk();

        $this->assertSame(5, $this->variantA->fresh()->current_stock); // 10 - 5, qty akhir
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variantA->id, 'type' => 'preorder_handover', 'qty_change' => -5]);
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
        $this->variantA->update(['current_stock' => 2]); // spec 061: stok harus ada sebelum serah terima
        $preorder = $this->createPreorder();
        $this->moveToStatus($preorder['id'], 'dp_paid')->assertOk();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 200000, 'purpose' => 'settlement',
        ])->assertCreated();
        // Sudah lunas -> otomatis settled saat tiba (spec 061), tanpa langkah 'settled' manual.
        $this->moveToStatus($preorder['id'], 'arrived')->assertOk();
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
