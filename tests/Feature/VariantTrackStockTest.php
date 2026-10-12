<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 028 — bendera `track_stock` pada varian: lewat pembuatan produk, tambah
 * varian, ubah varian, dan daftar produk. Default tetap TRUE.
 */
class VariantTrackStockTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $artist;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'inventory']), 'sanctum');
        $this->homeOutlet = Outlet::factory()->create();
        $this->category = Category::factory()->create();
    }

    private function createProduct(array $variant): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/products', [
            'home_outlet_id' => $this->homeOutlet->id, 'category_id' => $this->category->id, 'name' => 'Nasi Goreng',
            'variants' => [array_merge(['variant_name' => 'Standard', 'sell_price' => 30000], $variant)],
        ]);
    }

    public function test_a_variant_is_tracked_by_default(): void
    {
        $this->createProduct([])->assertCreated()->assertJsonPath('variants.0.track_stock', true);
    }

    public function test_a_made_to_order_variant_can_be_created_untracked(): void
    {
        $this->createProduct(['track_stock' => false])->assertCreated()->assertJsonPath('variants.0.track_stock', false);
        $this->assertDatabaseHas('product_variants', ['variant_name' => 'Standard', 'track_stock' => 0]);
    }

    public function test_a_variant_added_later_can_be_untracked(): void
    {
        $product = Product::find($this->createProduct([])->json('id'));

        $this->postJson("/api/v1/products/{$product->id}/variants", ['variant_name' => 'Jumbo', 'sell_price' => 40000, 'track_stock' => false])
            ->assertCreated()->assertJsonPath('track_stock', false);
    }

    public function test_the_flag_can_be_switched_on_an_existing_variant(): void
    {
        $variantId = $this->createProduct([])->json('variants.0.id');

        $this->putJson("/api/v1/variants/{$variantId}", ['track_stock' => false])->assertOk()->assertJsonPath('track_stock', false);
        $this->putJson("/api/v1/variants/{$variantId}", ['track_stock' => true])->assertOk()->assertJsonPath('track_stock', true);
    }

    public function test_the_product_list_exposes_the_flag_with_variants(): void
    {
        $this->createProduct(['track_stock' => false]);

        $this->getJson('/api/v1/products?with_variants=1')->assertOk()->assertJsonPath('data.0.variants.0.track_stock', false);
    }

    public function test_a_non_boolean_value_is_rejected(): void
    {
        $this->createProduct(['track_stock' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['variants.0.track_stock']);
    }
}
