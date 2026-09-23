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
 * 022-preorder-invoice-crud-overhaul (US2, FR-005/FR-006).
 */
class ShipmentAddressTest extends TestCase
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
            'sku' => 'SHIPA0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create(['address' => 'Jl. Mawar No. 5, Bandung']);
    }

    public function test_shipment_no_longer_accepts_or_stores_city_or_postal_code(): void
    {
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'courier',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $response = $this->postJson("/api/v1/preorders/{$preorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
            'address_line' => 'Jl. Test, Jakarta',
            // city/postal_code sengaja tetap dikirim di sini — harus
            // diabaikan begitu saja (kolomnya sudah tidak ada), bukan
            // menyebabkan galat.
            'city' => 'Jakarta', 'postal_code' => '40123',
        ]);

        $response->assertCreated();
        $this->assertFalse(\Schema::hasColumn('shipments', 'city'));
        $this->assertFalse(\Schema::hasColumn('shipments', 'postal_code'));
        $this->assertDatabaseHas('shipments', ['address_line' => 'Jl. Test, Jakarta']);
    }

    public function test_shipment_still_requires_address_line(): void
    {
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'courier',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $response = $this->postJson("/api/v1/preorders/{$preorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('address_line');
    }

    public function test_preorder_detail_response_no_longer_includes_city_or_postal_code(): void
    {
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'courier',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $this->postJson("/api/v1/preorders/{$preorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
            'address_line' => 'Jl. Test, Jakarta',
        ])->assertCreated();

        $show = $this->getJson("/api/v1/preorders/{$preorder['id']}")->json();

        $this->assertArrayNotHasKey('city', $show['shipment']);
        $this->assertArrayNotHasKey('postal_code', $show['shipment']);
        $this->assertEquals('Jl. Test, Jakarta', $show['shipment']['address_line']);
    }
}
