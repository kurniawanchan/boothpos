<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 007-preorder-import-export-notify (US1) — search param on GET /preorders.
 */
class PreorderSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makePreorderFor(string $customerName, string $status = 'ordered'): void
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);
        $variant = $product->variants()->create(['sku' => strtoupper(substr(md5($customerName), 0, 12)), 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
        $customer = Customer::factory()->create(['name' => $customerName]);

        $response = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ]);

        if ($status !== 'ordered') {
            $this->patchJson("/api/v1/preorders/{$response->json('id')}/status", ['status' => $status]);
        }
    }

    protected function actingAsCashier(): User
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_search_matches_partial_case_insensitive_customer_name(): void
    {
        $this->actingAsCashier();
        $this->makePreorderFor('Siti Amalia');
        $this->makePreorderFor('Budi Santoso');

        $response = $this->getJson('/api/v1/preorders?search=siti');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Siti Amalia', $rows[0]['customer_name']);
    }

    public function test_search_with_no_match_returns_empty_list_not_error(): void
    {
        $this->actingAsCashier();
        $this->makePreorderFor('Siti Amalia');

        $response = $this->getJson('/api/v1/preorders?search=zzz-no-match');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_search_combines_with_existing_status_filter(): void
    {
        $this->actingAsCashier();
        $this->makePreorderFor('Siti Amalia', 'ordered');
        $this->makePreorderFor('Siti Rahayu', 'dp_paid');

        $response = $this->getJson('/api/v1/preorders?search=siti&status=dp_paid');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Siti Rahayu', $rows[0]['customer_name']);
    }
}
