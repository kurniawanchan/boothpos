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
 * 024-invoice-layout-shipping-slip (Foundational, research.md Decision 1) —
 * `created_at` ditambahkan ke present() satu-satunya, jadi tiap konsumennya
 * (show, invoice, bulk-invoices) harus ikut mendapatkannya.
 */
class PreorderInvoiceCreatedAtTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'CRTD0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();
    }

    public function test_show_includes_created_at(): void
    {
        $preorder = $this->createPreorder();

        $this->getJson("/api/v1/preorders/{$preorder['id']}")
            ->assertOk()
            ->assertJsonPath('created_at', fn ($v) => ! is_null($v));
    }

    public function test_invoice_includes_created_at(): void
    {
        $preorder = $this->createPreorder();

        $this->getJson("/api/v1/preorders/{$preorder['id']}/invoice")
            ->assertOk()
            ->assertJsonPath('created_at', fn ($v) => ! is_null($v));
    }

    public function test_bulk_invoices_includes_created_at(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->postJson('/api/v1/preorders/bulk-invoices', [
            'preorder_ids' => [$preorder['id']], 'document' => 'invoice',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.0.created_at'));
    }
}
