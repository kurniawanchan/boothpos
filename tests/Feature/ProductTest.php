<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    private function baseline(): array
    {
        return [
            'artist' => Artist::factory()->create(['code' => 'RYU']),
            'category' => Category::factory()->create(['code' => 'KY']),
        ];
    }

    public function test_owner_can_create_product_with_generated_code_prefix(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'product_segment' => 'SAK',
            'name' => 'Keychain Sakura',
            'variants' => [
                ['variant_name' => 'Varian A', 'sell_price' => 25000],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('code_prefix', 'RYUKYSAK')
            ->assertJsonPath('variants.0.sku', 'RYUKYSAK0001');
    }

    public function test_segment_is_derived_from_name_when_not_provided(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'name' => 'Poster Yuki',
            'variants' => [['variant_name' => 'Standard', 'sell_price' => 15000]],
        ]);

        $response->assertCreated()->assertJsonPath('code_prefix', 'RYUKYPOS');
    }

    public function test_second_variant_gets_sequential_sku(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $product = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'product_segment' => 'SAK', 'name' => 'Keychain',
            'variants' => [['variant_name' => 'A', 'sell_price' => 20000]],
        ])->json();

        $response = $this->postJson("/api/v1/products/{$product['id']}/variants", [
            'variant_name' => 'B', 'sell_price' => 22000,
        ]);

        $response->assertCreated()->assertJsonPath('sku', 'RYUKYSAK0002');
    }

    public function test_duplicate_code_prefix_is_rejected(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $payload = [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'product_segment' => 'SAK', 'name' => 'Keychain Sakura',
            'variants' => [['variant_name' => 'A', 'sell_price' => 20000]],
        ];

        $this->postJson('/api/v1/products', $payload)->assertCreated();

        $response = $this->postJson('/api/v1/products', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors('product_segment');
    }

    public function test_product_requires_at_least_one_variant(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Tanpa Varian', 'variants' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('variants');
    }

    public function test_preorder_product_requires_eta(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $response = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Pre-order Figure', 'is_preorder' => true,
            'variants' => [['variant_name' => 'A', 'sell_price' => 500000]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('preorder_eta');
    }

    public function test_cashier_cannot_create_product(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'name' => 'Test', 'variants' => [['variant_name' => 'A', 'sell_price' => 1000]],
        ])->assertStatus(403);
    }

    public function test_product_with_active_variant_cannot_be_deleted(): void
    {
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000, 'is_active' => true]);

        $this->deleteJson("/api/v1/products/{$product->id}")->assertStatus(409);
    }

    public function test_product_list_omits_variants_by_default(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 25000, 'current_stock' => 7]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $this->assertArrayNotHasKey('variants', $response->json('data.0'));
    }

    // Menutup N+1 di layar kasir: PosView dulu mengambil detail tiap produk
    // satu per satu hanya untuk mendapatkan varian.
    public function test_product_list_includes_variants_when_asked(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 25000, 'current_stock' => 7]);
        $product->variants()->create(['sku' => 'RYUKYAAA0002', 'sell_price' => 30000, 'current_stock' => 3]);

        $response = $this->getJson('/api/v1/products?with_variants=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data.0.variants')
            ->assertJsonPath('data.0.variants.0.sku', 'RYUKYAAA0001')
            ->assertJsonPath('data.0.variants.0.sell_price', '25000.00')
            ->assertJsonPath('data.0.variants.0.current_stock', 7);
    }

    // Varian dimuat lewat eager-load, bukan lazy-load per baris di resource.
    public function test_product_list_with_variants_does_not_run_a_query_per_product(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();

        foreach (range(1, 5) as $i) {
            $product = Product::factory()->create([
                'artist_id' => $artist->id,
                'category_id' => $category->id,
                'code_prefix' => 'RYUKYP'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
            $product->variants()->create(['sku' => 'RYUKYP'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'0001', 'sell_price' => 1000]);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson('/api/v1/products?with_variants=1')->assertOk();
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $variantQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'product_variants'))->count();

        $this->assertSame(1, $variantQueries, 'Varian harus dimuat dalam satu query eager-load, bukan satu query per produk.');
    }

    public function test_variant_lookup_finds_by_sku_fragment(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'name' => 'Keychain Sakura']);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'sell_price' => 25000, 'current_stock' => 10]);

        $response = $this->getJson('/api/v1/variants/lookup?q=SAK0001');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('RYUKYSAK0001', $response->json('data.0.sku'));
    }

    public function test_variant_lookup_only_returns_active_variants(): void
    {
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $product->variants()->create(['sku' => 'RYUKYAAA0001', 'sell_price' => 1000, 'is_active' => false]);

        $response = $this->getJson('/api/v1/variants/lookup?q=AAA0001');

        $this->assertCount(0, $response->json('data'));
    }

    // =====================================================================
    // Task 5 — unggah gambar produk.
    // =====================================================================

    public function test_owner_can_upload_a_product_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $response = $this->post("/api/v1/products/{$product->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('produk.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('image_url'));
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($product->fresh()->image_path);
    }

    public function test_uploading_a_disguised_non_image_file_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $response = $this->post("/api/v1/products/{$product->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->create('produk.jpg', 10, 'application/pdf'),
        ]);

        $response->assertStatus(422);
    }

    public function test_cashier_cannot_upload_a_product_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('cashier');
        ['artist' => $artist, 'category' => $category] = $this->baseline();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);

        $response = $this->post("/api/v1/products/{$product->id}/image", [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('produk.jpg'),
        ]);

        $response->assertStatus(403);
    }
}
