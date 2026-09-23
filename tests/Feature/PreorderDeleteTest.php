<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 022-preorder-invoice-crud-overhaul (US1, FR-003/FR-004).
 */
class PreorderDeleteTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
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
        $this->variant = $product->variants()->create([
            'sku' => 'DELA0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();
    }

    public function test_delete_succeeds_while_status_is_ordered_with_no_payment(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->deleteJson("/api/v1/preorders/{$preorder['id']}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('preorders', ['id' => $preorder['id']]);
        $this->assertDatabaseMissing('preorder_items', ['preorder_id' => $preorder['id']]);
    }

    public function test_delete_is_refused_at_dp_paid_status(): void
    {
        $preorder = $this->createPreorder();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 50000, 'purpose' => 'down_payment',
        ])->assertCreated();

        $response = $this->deleteJson("/api/v1/preorders/{$preorder['id']}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('preorders', ['id' => $preorder['id']]);
    }

    public function test_delete_is_refused_at_arrived_status(): void
    {
        $preorder = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $response = $this->deleteJson("/api/v1/preorders/{$preorder['id']}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('preorders', ['id' => $preorder['id']]);
    }

    public function test_delete_is_refused_when_a_payment_exists_even_at_ordered_looking_state(): void
    {
        // A payment can never actually exist while status is still literally
        // "ordered" in this codebase's own state machine (recording any
        // payment auto-transitions to dp_paid) — this test only proves the
        // payment-existence guard stands on its own, per FR-004's own wording,
        // regardless of status.
        $preorder = $this->createPreorder();
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 50000, 'purpose' => 'down_payment',
        ])->assertCreated();

        \DB::table('preorders')->where('id', $preorder['id'])->update(['status' => 'ordered']);

        $response = $this->deleteJson("/api/v1/preorders/{$preorder['id']}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('preorders', ['id' => $preorder['id']]);
    }
}
