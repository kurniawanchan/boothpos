<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §6) — etalase publik tidak pernah membocorkan
 * data khusus staf. Area risiko: excessive data exposure.
 *
 * Dua lapis bukti: (1) DAFTAR PUTIH kunci di tiap tingkat sarang, sehingga
 * field baru di model/resource staf tidak bisa ikut keluar tanpa mengubah
 * test ini; (2) daftar hitam eksplisit dari kontrak plus pemeriksaan NILAI
 * rahasia di badan respons mentah (harga modal, SKU, kontak seller, …).
 */
class StorefrontRedactionTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    private const CARD_KEYS = ['slug', 'name', 'image_url', 'category', 'seller_name', 'price_min', 'price_max', 'in_stock'];

    private const DETAIL_KEYS = [...self::CARD_KEYS, 'description', 'variants'];

    private const VARIANT_KEYS = ['id', 'name', 'image_url', 'price', 'in_stock', 'max_qty'];

    /** Kunci yang kontrak larang, di tingkat sarang mana pun. */
    private const FORBIDDEN_KEYS = [
        'cost_price', 'current_stock', 'low_stock_alert', 'is_low_stock', 'sku', 'active_sku', 'sku_is_typed', 'sku_snapshot',
        'code_prefix', 'product_segment', 'image_path', 'bom_complete', 'bom_completed_at', 'bom_completed_by', 'has_bom',
        'bom_cost', 'bom_lines', 'data_mode', 'contact_phone', 'contact_email', 'notes', 'product_id', 'artist_id',
        'category_id', 'artist', 'seller', 'is_active', 'is_preorder', 'preorder_eta', 'published_at', 'created_at',
        'updated_at', 'deleted_at', 'total_stock', 'total_stock_value', 'stock', 'parent_id', 'display_order',
        'product_count', 'sell_price', 'variant_name',
    ];

    /** @var array{product: Product, variant: ProductVariant, artist: Artist, category: Category} */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableCustomerCommerce();

        $artist = Artist::factory()->create([
            'name' => 'Studio Publik', 'contact_phone' => '0811-RAHASIA', 'contact_email' => 'kontak-rahasia@seller.test',
            'notes' => 'CATATAN-INTERNAL-SELLER',
        ]);
        $category = Category::factory()->create(['name' => 'Poster', 'image_path' => 'categories/poster.png']);

        [$product, $variant] = $this->makeStorefrontProduct([
            'name' => 'Poster Rahasia Dagang', 'description' => 'Deskripsi untuk publik.', 'image_path' => 'products/poster.jpg',
            'artist_id' => $artist->id, 'category_id' => $category->id,
        ], 45000, 37);

        // Nilai-nilai khas supaya kebocoran lewat NILAI (bukan hanya kunci) ikut tertangkap.
        $variant->update(['cost_price' => 7777, 'low_stock_alert' => 13, 'image_path' => 'variants/poster-a3.png', 'bom_complete' => true]);

        $this->world = ['product' => $product->fresh(), 'variant' => $variant->fresh(), 'artist' => $artist, 'category' => $category];
    }

    /**
     * Semua kunci objek di tingkat mana pun (kunci daftar numerik dilewati).
     *
     * @return list<string>
     */
    private function allKeys(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $keys = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $keys = [...$keys, ...$this->allKeys($value)];
        }

        return array_values(array_unique($keys));
    }

    private function assertNothingSensitive(string $label, string $body, array $json): void
    {
        $this->assertSame([], array_values(array_intersect(self::FORBIDDEN_KEYS, $this->allKeys($json))), "{$label}: kunci terlarang ikut keluar.");

        $variant = $this->world['variant'];
        $secrets = [
            'harga modal' => '7777.00',
            'SKU' => $variant->sku,
            'kode produk' => $this->world['product']->code_prefix,
            'segmen produk' => '"'.$this->world['product']->product_segment.'"',
            // Jalur disk mentah (bukan URL): json_encode menyamakan cara garis miring di-escape di badan respons.
            'jalur berkas produk' => json_encode('products/poster.jpg'),
            'jalur berkas varian' => json_encode('variants/poster-a3.png'),
            'jalur berkas kategori' => json_encode('categories/poster.png'),
            'telepon seller' => '0811-RAHASIA',
            'e-mail seller' => 'kontak-rahasia@seller.test',
            'catatan seller' => 'CATATAN-INTERNAL-SELLER',
            'kode seller' => '"'.$this->world['artist']->code.'"',
            'mode data' => '"live"',
        ];

        foreach ($secrets as $what => $value) {
            $this->assertStringNotContainsString((string) $value, $body, "{$label}: {$what} ikut keluar.");
        }
    }

    public function test_the_list_exposes_only_the_card_fields(): void
    {
        $response = $this->getJson('/api/v1/storefront/products')->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($response->json('meta')));
        $this->assertSame(self::CARD_KEYS, array_keys($response->json('data.0')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0.category')));
        // Id numerik produk tidak pernah keluar: pengenal publiknya adalah slug.
        $this->assertArrayNotHasKey('id', $response->json('data.0'));

        $this->assertNothingSensitive('daftar', $response->getContent(), $response->json());
    }

    public function test_the_detail_exposes_only_the_card_description_and_public_variant_fields(): void
    {
        $response = $this->getJson('/api/v1/storefront/products/poster-rahasia-dagang')->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(self::DETAIL_KEYS, array_keys($response->json('data')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.category')));
        $this->assertSame(self::VARIANT_KEYS, array_keys($response->json('data.variants.0')));
        $this->assertArrayNotHasKey('id', $response->json('data'));

        // Stok persisnya (37) dan ambang stok menipis (13) tidak keluar; yang tampil batas beli per baris (20).
        $response->assertJsonPath('data.variants.0.max_qty', 20);
        $publicValues = array_values(array_diff_key($response->json('data.variants.0'), ['id' => true]));
        $this->assertNotContains(37, $publicValues);
        $this->assertNotContains(13, $publicValues);

        $this->assertNothingSensitive('detail', $response->getContent(), $response->json());
    }

    public function test_categories_expose_only_id_name_and_image_url(): void
    {
        $response = $this->getJson('/api/v1/storefront/categories')->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['id', 'name', 'image_url'], array_keys($response->json('data.0')));

        $this->assertNothingSensitive('kategori', $response->getContent(), $response->json());
    }

    public function test_error_bodies_do_not_reveal_internals_either(): void
    {
        $this->world['product']->forceFill(['published_at' => null])->save();

        $response = $this->getJson('/api/v1/storefront/products/poster-rahasia-dagang')->assertNotFound();

        $this->assertNothingSensitive('404', $response->getContent(), $response->json());
        foreach (['Product', 'App\\', 'SQL', 'published', 'slug'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->json('detail').$response->json('title'));
        }
    }
}
