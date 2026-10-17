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

    /** Spec 045 FR-016: varian yang sudah dihapus tidak diregenerasi (SKU-nya dibiarkan). */
    public function test_a_removed_variant_is_left_alone_and_a_live_one_is_regenerated(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1', 'RYU-KY-SH-3']);
        $product->variants()->where('sku', 'RYU-KY-SH-3')->firstOrFail()->delete();

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-1']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-3']);
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

        foreach (['ABCDEFG', 'S1', '-S', 'SH-', 'S--K'] as $segment) {
            $this->changeSegment($product, $segment)->assertStatus(422)->assertJsonValidationErrors('product_segment');
        }

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-1']);
    }

    /** Spec 046 (T007): segmen dengan tanda hubung masuk utuh; nomor tiap varian tetap. */
    public function test_a_hyphenated_segment_keeps_every_number(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1', 'RYU-KY-SH-2']);

        $this->changeSegment($product, 'sh-k')->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'code_prefix' => 'RYU-KY-SH-K', 'product_segment' => 'SH-K']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-K-1']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-K-2']);
    }

    /** Spec 046 FR-004 (T023): segmen yang membuat SKU lebih dari 20 karakter ditolak; tidak ada yang berubah. */
    public function test_a_segment_that_pushes_a_sku_past_twenty_characters_is_refused_and_nothing_changes(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-1234567']);

        $this->changeSegment($product, 'ABCDEF')->assertStatus(422)->assertJsonValidationErrors('product_segment');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'code_prefix' => 'RYU-KY-SH', 'product_segment' => 'SH']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-SH-1234567']);
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

    /** Spec 045 US3 (FR-010, diubah 2026-10-17): nomor yang diketik tetap; kodenya ikut segmen baru. */
    public function test_a_typed_number_is_kept_and_its_code_follows_the_new_segment(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-7', 'RYU-KY-SH-2']);
        $product->variants()->where('sku', 'RYU-KY-SH-7')->update(['sku_is_typed' => true]);

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-7', 'sku_is_typed' => true]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-2', 'sku_is_typed' => false]);
        $this->assertDatabaseMissing('product_variants', ['sku' => 'RYU-KY-SH-7']);
    }

    /** Typed SKU dengan kode lama yang lain (mis. hasil ganti segmen sebelumnya) tetap ikut kode produk sekarang. */
    public function test_a_typed_sku_under_an_older_code_is_moved_to_the_current_code(): void
    {
        $this->actAs('owner');
        $product = $this->productWithSkus('SH', ['RYU-KY-T-2']);
        $product->variants()->update(['sku_is_typed' => true]);

        $this->changeSegment($product, 'S')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-S-2', 'sku_is_typed' => true]);
    }

    /** Nomor diketik didahulukan saat bentrok: yang dibuat server bergeser ke nomor kosong berikutnya. */
    public function test_a_generated_sku_yields_a_collision_to_a_typed_number(): void
    {
        $this->actAs('owner');
        // Yang dibuat server bernomor 2 (sama dengan SKU diketik di kode baru) → harus bergeser ke 3.
        $product = $this->productWithSkus('SH', ['RYU-KY-SH-2', 'RYU-KY-T-2']);
        $product->variants()->where('sku', 'RYU-KY-T-2')->update(['sku_is_typed' => true]);

        $this->changeSegment($product, 'T')->assertOk();

        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-T-2', 'sku_is_typed' => true]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'RYU-KY-T-3', 'sku_is_typed' => false]);
    }
}
