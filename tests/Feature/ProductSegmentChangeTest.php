<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Spec 044 US3 (FR-006/FR-007): ganti segmen = SKU semua varian ikut diregenerasi. */
class ProductSegmentChangeTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist = Artist::factory()->create(['code' => 'RYU']);
        $this->category = Category::factory()->create(['code' => 'KY']);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** @param array<int, string> $skus */
    private function productWithSkus(string $segment, array $skus): Product
    {
        $product = Product::factory()->create([
            'artist_id' => $this->artist->id,
            'category_id' => $this->category->id,
            'code_prefix' => "RYU-KY-{$segment}",
            'product_segment' => $segment,
        ]);
        foreach ($skus as $sku) {
            $product->variants()->create(['sku' => $sku, 'variant_name' => $sku, 'sell_price' => 15000, 'current_stock' => 0]);
        }

        return $product;
    }

    private function changeSegment(Product $product, string $segment): TestResponse
    {
        return $this->putJson("/api/v1/products/{$product->id}", ['name' => $product->name, 'product_segment' => $segment]);
    }

    public function test_changing_the_segment_regenerates_every_variant_sku_and_keeps_its_number(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1', 'RYU-KY-SH-2']);

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'code_prefix' => 'RYU-KY-S', 'product_segment' => 'S']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-1']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-2']);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'RYU-KY-SH-1']);
    }

    public function test_a_padded_old_sku_becomes_the_plain_number_under_the_new_segment(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-001']);

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-1']);
    }

    public function test_a_deleted_variant_is_regenerated_too(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1', 'RYU-KY-SH-3']);
        $product->variants()->where('sku', 'RYU-KY-SH-3')->firstOrFail()->delete();

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-3']);
    }

    public function test_the_same_or_a_blank_segment_changes_nothing(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1']);

        $this->changeSegment($product, 'SH')->assertOk();
        $this->changeSegment($product, '')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-1']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'code_prefix' => 'RYU-KY-SH']);
    }

    public function test_a_code_already_used_by_another_product_is_refused_and_nothing_changes(): void
    {
        $this->actAs('owner');
        Product::factory()->create([
            'artist_id' => $this->artist->id, 'category_id' => $this->category->id,
            'code_prefix' => 'RYU-KY-S', 'product_segment' => 'S',
        ]);
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1']);

        $this->changeSegment($product, 'S')
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_segment');

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-1']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'code_prefix' => 'RYU-KY-SH']);
    }

    public function test_an_invalid_segment_is_refused_and_nothing_changes(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1']);

        foreach (['ABCD', 'S1'] as $segment) {
            $this->changeSegment($product, $segment)->assertStatus(422)->assertJsonValidationErrors('product_segment');
        }

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-1']);
    }

    public function test_the_change_is_written_to_the_activity_log(): void
    {
        $owner = $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1']);

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'segment_changed',
            'entity_type' => 'Product',
            'entity_id' => $product->id,
        ]);
    }

    /** Riwayat tidak disentuh: sku_snapshot penjualan lama tetap SKU yang dicatat. */
    public function test_a_past_sale_keeps_the_sku_it_was_recorded_with(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1']);
        $variant = ProductVariant::where('sku', 'RYU-KY-SH-1')->firstOrFail();
        $variant->update(['current_stock' => 10, 'cost_price' => 5000]);

        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertCreated();

        $this->actAs('owner');
        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'RYU-KY-S-1']);
        $this->assertSame('RYU-KY-SH-1', DB::table('order_items')->where('variant_id', $variant->id)->value('sku_snapshot'));
    }
}
