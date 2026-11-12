<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageUploadService;
use App\Services\StockService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §6) — etalase publik: GET /storefront/categories,
 * /storefront/products dan /storefront/products/{slug}.
 *
 * Yang dijaga: hanya produk/varian yang LAYAK TAMPIL yang pernah keluar
 * (aturannya satu, di Product::scopeStorefrontVisible dan
 * ProductVariant::scopeStorefrontSellable), tiap syarat diuji SENDIRI-SENDIRI,
 * dan masukan publik (cari, urut, halaman) dibatasi. Bentuk kartu/detail
 * adalah kontrak dengan aplikasi Android, jadi dikunci dengan assertExactJson.
 */
class StorefrontCatalogTest extends TestCase
{
    use CustomerCommerceFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableCustomerCommerce();
    }

    private function listing(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/storefront/products'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @return list<string> */
    private function listedNames(array $query = []): array
    {
        return $this->listing($query)->assertOk()->json('data.*.name');
    }

    private function assertProblem(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code)->assertJsonPath('status', $status);
        $this->assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(
            ['type', 'title', 'status', 'detail', 'code', 'request_id'],
            array_values(array_diff(array_keys($response->json()), ['errors'])),
        );
    }

    // ------------------------------------------------------------------
    // Bentuk dan isi
    // ------------------------------------------------------------------

    public function test_the_list_shows_only_the_visible_product_as_a_card(): void
    {
        $w = $this->buildStorefrontWorld();

        $this->listing()->assertOk()->assertExactJson([
            'data' => [[
                'slug' => 'gantungan-kunci-sakura',
                'name' => 'Gantungan Kunci Sakura',
                'image_url' => null,
                'category' => ['id' => $w['category']->id, 'name' => 'Gantungan Kunci'],
                'seller_name' => 'Seller Etalase',
                // Hanya varian layak jual (Merah 25.000, Biru 30.000) yang membentuk rentang harga.
                'price_min' => '25000.00',
                'price_max' => '30000.00',
                'in_stock' => true,
            ]],
            'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
        ]);
    }

    public function test_the_detail_shows_the_card_plus_description_and_sellable_variants_only(): void
    {
        $w = $this->buildStorefrontWorld();

        $this->getJson('/api/v1/storefront/products/gantungan-kunci-sakura')->assertOk()->assertExactJson(['data' => [
            'slug' => 'gantungan-kunci-sakura',
            'name' => 'Gantungan Kunci Sakura',
            'image_url' => null,
            'category' => ['id' => $w['category']->id, 'name' => 'Gantungan Kunci'],
            'seller_name' => 'Seller Etalase',
            'price_min' => '25000.00',
            'price_max' => '30000.00',
            'in_stock' => true,
            'description' => 'Akrilik dua sisi.',
            // Hijau (stok 0), Kuning (nonaktif) dan Ungu (belum terbit) tidak pernah keluar.
            'variants' => [
                ['id' => $w['variantA']->id, 'name' => 'Merah', 'image_url' => null, 'price' => '25000.00', 'in_stock' => true, 'max_qty' => 10],
                ['id' => $w['variantB']->id, 'name' => 'Biru', 'image_url' => null, 'price' => '30000.00', 'in_stock' => true, 'max_qty' => 5],
            ],
        ]]);
    }

    public function test_a_product_without_description_reports_null(): void
    {
        $this->makeStorefrontProduct(['name' => 'Tanpa Deskripsi', 'description' => null]);

        $this->getJson('/api/v1/storefront/products/tanpa-deskripsi')->assertOk()->assertJsonPath('data.description', null);
    }

    public function test_image_urls_are_absolute_and_a_variant_falls_back_to_the_product_image(): void
    {
        [$product, $plain] = $this->makeStorefrontProduct(['name' => 'Poster Bergambar', 'image_path' => 'products/poster.jpg']);
        $own = $this->addStorefrontVariant($product, 'Bergambar Sendiri', 12000, 3);
        $own->update(['image_path' => 'variants/own.png']);

        $images = app(ImageUploadService::class);
        $productUrl = $images->url('products/poster.jpg');
        $this->assertMatchesRegularExpression('#^https?://#', $productUrl);

        $this->listing()->assertOk()->assertJsonPath('data.0.image_url', $productUrl);

        $detail = $this->getJson('/api/v1/storefront/products/poster-bergambar')->assertOk();
        $detail->assertJsonPath('data.image_url', $productUrl);
        $byId = collect($detail->json('data.variants'))->keyBy('id');
        $this->assertSame($productUrl, $byId[$plain->id]['image_url'], 'Varian tanpa gambar memakai gambar produk.');
        $this->assertSame($images->url('variants/own.png'), $byId[$own->id]['image_url']);
    }

    public function test_max_qty_is_the_stock_capped_by_the_per_line_limit(): void
    {
        [$product, $small] = $this->makeStorefrontProduct(['name' => 'Stiker Banyak'], 5000, 4);
        $large = $this->addStorefrontVariant($product, 'Banyak', 5000, 75);

        $maxQty = fn () => collect($this->getJson('/api/v1/storefront/products/stiker-banyak')->assertOk()->json('data.variants'))
            ->pluck('max_qty', 'id')->all();

        // Stok persisnya (75) tidak pernah keluar: yang tampil hanya batas beli per baris.
        $this->assertSame([$small->id => 4, $large->id => 20], $maxQty());

        config(['customer_commerce.max_qty_per_line' => 3]);
        $this->assertSame([$small->id => 3, $large->id => 3], $maxQty());
    }

    public function test_the_catalogue_reacts_to_stock_running_out(): void
    {
        [, $variant] = $this->makeStorefrontProduct(['name' => 'Tinggal Dua'], 5000, 2);
        $this->assertSame(['Tinggal Dua'], $this->listedNames());

        app(StockService::class)->applyMovement($variant, 'adjustment', -2, reason: 'habis');

        $this->assertSame([], $this->listedNames());
        $this->assertProblem($this->getJson('/api/v1/storefront/products/tinggal-dua'), 404, 'not_found');
    }

    // ------------------------------------------------------------------
    // Tiap syarat kelayakan, satu per satu
    // ------------------------------------------------------------------

    public static function hiddenProductCases(): array
    {
        return [
            'produk belum terbit' => ['unpublished_product'],
            'produk terbit di masa depan' => ['future_product'],
            'produk nonaktif' => ['inactive_product'],
            'produk dihapus' => ['deleted_product'],
            'produk pre-order' => ['preorder_product'],
            'seller nonaktif' => ['inactive_seller'],
            'seller dihapus' => ['deleted_seller'],
            'kategori nonaktif' => ['inactive_category'],
            'kategori dihapus' => ['deleted_category'],
            'tanpa varian berstok' => ['no_stock'],
            'mode data lain' => ['other_data_mode'],
        ];
    }

    /**
     * Produk "tersembunyi" dibuat LAYAK TAMPIL sepenuhnya lalu dirusak tepat
     * satu syaratnya, supaya tiap kasus benar-benar menguji syarat itu saja
     * (test di bawah membuktikan keadaan awalnya memang tampil).
     *
     * @return array{category: Category, artist: Artist, product: Product, variant: ProductVariant}
     */
    private function almostVisibleProduct(int $stock = 5): array
    {
        $category = Category::factory()->create(['name' => 'Kategori Tersembunyi']);
        $artist = Artist::factory()->create(['name' => 'Seller Tersembunyi']);
        [$product, $variant] = $this->makeStorefrontProduct(
            ['name' => 'Produk Tersembunyi', 'artist_id' => $artist->id, 'category_id' => $category->id],
            99000,
            $stock,
        );

        return compact('category', 'artist', 'product', 'variant');
    }

    public function test_the_almost_hidden_product_is_visible_until_a_rule_is_broken(): void
    {
        $this->makeStorefrontProduct(['name' => 'Produk Dasar']);
        $hidden = $this->almostVisibleProduct();

        $this->assertEqualsCanonicalizing(['Produk Dasar', 'Produk Tersembunyi'], $this->listedNames());
        $this->getJson('/api/v1/storefront/products/produk-tersembunyi')->assertOk()
            ->assertJsonPath('data.variants.0.id', $hidden['variant']->id);
        $this->assertContains('Kategori Tersembunyi', $this->getJson('/api/v1/storefront/categories')->assertOk()->json('data.*.name'));
    }

    #[DataProvider('hiddenProductCases')]
    public function test_a_product_failing_one_rule_never_leaks(string $case): void
    {
        [$baseline] = $this->makeStorefrontProduct(['name' => 'Produk Dasar']);

        $hidden = match ($case) {
            'other_data_mode' => ModeGate::runAs('demo', fn () => $this->almostVisibleProduct()),
            'no_stock' => $this->almostVisibleProduct(stock: 0),
            default => $this->almostVisibleProduct(),
        };

        match ($case) {
            'unpublished_product' => $hidden['product']->forceFill(['published_at' => null])->save(),
            'future_product' => $this->publishForStorefront($hidden['product'], now()->addMinutes(5)),
            'inactive_product' => $hidden['product']->update(['is_active' => false]),
            'deleted_product' => $hidden['product']->delete(),
            'preorder_product' => $hidden['product']->update(['is_preorder' => true]),
            'inactive_seller' => $hidden['artist']->update(['is_active' => false]),
            'deleted_seller' => $hidden['artist']->delete(),
            'inactive_category' => $hidden['category']->update(['is_active' => false]),
            'deleted_category' => $hidden['category']->delete(),
            default => null,
        };

        // Daftar: hanya produk dasar, juga saat dicari atau disaring ke kategorinya.
        $list = $this->listing()->assertOk();
        $this->assertSame(['Produk Dasar'], $list->json('data.*.name'));
        $list->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.price_min', '10000.00')
            ->assertJsonPath('data.0.price_max', '10000.00');
        $this->assertStringNotContainsString('Tersembunyi', $list->getContent());
        $this->assertStringNotContainsString('99000', $list->getContent());

        $this->assertSame([], $this->listedNames(['q' => 'Tersembunyi']));
        $this->assertSame([], $this->listedNames(['category_id' => $hidden['category']->id]));

        // Detail: tidak ditemukan, tanpa membedakan "ada tapi disembunyikan" dari "tidak ada".
        $missing = $this->getJson('/api/v1/storefront/products/produk-tersembunyi');
        $this->assertProblem($missing, 404, 'not_found');
        $this->assertSame(
            $this->getJson('/api/v1/storefront/products/tidak-pernah-ada')->json('detail'),
            $missing->json('detail'),
        );

        // Kategori: hanya kategori produk dasar.
        $this->assertSame(
            [$baseline->category->name],
            $this->getJson('/api/v1/storefront/categories')->assertOk()->json('data.*.name'),
        );
    }

    public static function hiddenVariantCases(): array
    {
        return [
            'varian belum terbit' => ['unpublished'],
            'varian terbit di masa depan' => ['future'],
            'varian nonaktif' => ['inactive'],
            'varian dihapus' => ['deleted'],
            'varian stok nol' => ['zero_stock'],
        ];
    }

    #[DataProvider('hiddenVariantCases')]
    public function test_a_variant_failing_one_rule_never_leaks_into_the_detail_or_the_price_range(string $case): void
    {
        [$product, $good] = $this->makeStorefrontProduct(['name' => 'Produk Dasar'], 10000, 5);

        // Dua varian tersembunyi, satu lebih murah dan satu lebih mahal: keduanya tak boleh menggeser rentang harga.
        foreach ([1000, 99000] as $price) {
            $hidden = $this->addStorefrontVariant($product, 'Tersembunyi', $price, $case === 'zero_stock' ? 0 : 9, published: $case !== 'unpublished');

            match ($case) {
                'future' => $this->publishForStorefront($hidden, now()->addMinutes(5)),
                'inactive' => $hidden->update(['is_active' => false]),
                'deleted' => $hidden->delete(),
                default => null,
            };
        }

        $this->listing()->assertOk()
            ->assertJsonPath('data.0.price_min', '10000.00')
            ->assertJsonPath('data.0.price_max', '10000.00');

        $detail = $this->getJson('/api/v1/storefront/products/produk-dasar')->assertOk();
        $this->assertSame([$good->id], $detail->json('data.variants.*.id'));
        $detail->assertJsonPath('data.price_min', '10000.00')->assertJsonPath('data.price_max', '10000.00');
        $this->assertStringNotContainsString('Tersembunyi', $detail->getContent());
        $this->assertStringNotContainsString('99000', $detail->getContent());
    }

    public function test_the_catalogue_follows_the_active_data_mode(): void
    {
        $this->makeStorefrontProduct(['name' => 'Produk Live']);
        ModeGate::runAs('demo', fn () => $this->makeStorefrontProduct(['name' => 'Produk Demo']));

        $this->assertSame(['Produk Live'], $this->listedNames());
        $this->getJson('/api/v1/storefront/products/produk-demo')->assertNotFound();

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo']);

        $this->assertSame(['Produk Demo'], $this->listedNames());
        $this->getJson('/api/v1/storefront/products/produk-demo')->assertOk();
        $this->getJson('/api/v1/storefront/products/produk-live')->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/storefront/categories')->assertOk()->json('data'));
    }

    // ------------------------------------------------------------------
    // Kategori
    // ------------------------------------------------------------------

    public function test_categories_list_only_those_with_a_visible_product(): void
    {
        $w = $this->buildStorefrontWorld();
        $w['category']->update(['image_path' => 'categories/gantungan.png', 'display_order' => 2]);
        $first = Category::factory()->create(['name' => 'Stiker', 'display_order' => 1]);
        $this->makeStorefrontProduct(['name' => 'Stiker Neko', 'category_id' => $first->id]);
        Category::factory()->create(['name' => 'Kategori Kosong']);

        $this->getJson('/api/v1/storefront/categories')->assertOk()->assertExactJson(['data' => [
            ['id' => $first->id, 'name' => 'Stiker', 'image_url' => null],
            ['id' => $w['category']->id, 'name' => 'Gantungan Kunci', 'image_url' => app(ImageUploadService::class)->url('categories/gantungan.png')],
        ]]);
    }

    // ------------------------------------------------------------------
    // Halaman, urutan, saringan, pencarian
    // ------------------------------------------------------------------

    public function test_pagination_meta_and_pages(): void
    {
        foreach (['Alfa', 'Beta', 'Gama'] as $name) {
            $this->makeStorefrontProduct(['name' => $name]);
        }

        $this->listing(['sort' => 'name', 'per_page' => 2])->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.*.name', ['Alfa', 'Beta']);

        $this->listing(['sort' => 'name', 'per_page' => 2, 'page' => 2])->assertOk()
            ->assertJsonPath('meta', ['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.*.name', ['Gama']);

        // Halaman di luar jangkauan: kosong, bukan galat.
        $this->listing(['per_page' => 2, 'page' => 9])->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 3);
    }

    public function test_per_page_defaults_to_twenty_and_is_capped_at_fifty(): void
    {
        $this->makeStorefrontProduct(['name' => 'Satu']);

        $this->listing()->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->listing(['per_page' => 50])->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->listing(['per_page' => 51])->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->listing(['per_page' => 100000])->assertOk()->assertJsonPath('meta.per_page', 50);
    }

    public static function invalidQueryCases(): array
    {
        return [
            'sort tak dikenal' => [['sort' => 'cost_price'], 'sort'],
            'sort berupa SQL' => [['sort' => 'name; DROP TABLE products'], 'sort'],
            'per_page nol' => [['per_page' => 0], 'per_page'],
            'per_page bukan angka' => [['per_page' => 'banyak'], 'per_page'],
            'page nol' => [['page' => 0], 'page'],
            'page terlalu besar' => [['page' => 9000000000000000000], 'page'],
            'category_id bukan angka' => [['category_id' => 'abc'], 'category_id'],
            'q terlalu panjang' => [['q' => str_repeat('x', 101)], 'q'],
            'q berupa array' => [['q' => ['a', 'b']], 'q'],
        ];
    }

    #[DataProvider('invalidQueryCases')]
    public function test_invalid_query_values_are_refused_with_a_problem_body(array $query, string $field): void
    {
        $this->makeStorefrontProduct(['name' => 'Satu']);

        $response = $this->listing($query);

        $this->assertProblem($response, 422, 'validation_failed');
        $this->assertSame([$field], array_keys($response->json('errors')));
    }

    public function test_unknown_query_parameters_are_ignored(): void
    {
        $this->makeStorefrontProduct(['name' => 'Satu']);

        $this->assertSame(['Satu'], $this->listedNames(['is_active' => 0, 'artist_id' => 999, 'with_variants' => 1, 'search' => 'tidak-ada']));
        // Parameter kosong sama dengan tidak dikirim.
        $this->assertSame(['Satu'], $this->listedNames(['q' => '', 'category_id' => '', 'sort' => '']));
    }

    public function test_sorting_uses_only_the_four_allowed_orders(): void
    {
        foreach ([['Beta', 20000, 3], ['Alfa', 30000, 1], ['Gama', 10000, 2]] as [$name, $price, $daysAgo]) {
            [$product] = $this->makeStorefrontProduct(['name' => $name], $price);
            $this->publishForStorefront($product, now()->subDays($daysAgo));
        }
        // Varian termahal Gama (50.000) menaikkan harga TERTINGGI-nya, bukan harga terendahnya.
        $this->addStorefrontVariant(Product::where('name', 'Gama')->firstOrFail(), 'Besar', 50000, 2);

        $this->assertSame(['Alfa', 'Gama', 'Beta'], $this->listedNames(), 'Bawaan: terbit paling baru dulu.');
        $this->assertSame(['Alfa', 'Gama', 'Beta'], $this->listedNames(['sort' => 'newest']));
        $this->assertSame(['Alfa', 'Beta', 'Gama'], $this->listedNames(['sort' => 'name']));
        $this->assertSame(['Gama', 'Beta', 'Alfa'], $this->listedNames(['sort' => 'price_asc']), 'Menurut harga terendah.');
        $this->assertSame(['Gama', 'Alfa', 'Beta'], $this->listedNames(['sort' => 'price_desc']), 'Menurut harga tertinggi.');
    }

    public function test_the_category_filter_narrows_the_list(): void
    {
        $stickers = Category::factory()->create(['name' => 'Stiker']);
        $this->makeStorefrontProduct(['name' => 'Stiker Neko', 'category_id' => $stickers->id]);
        $this->makeStorefrontProduct(['name' => 'Poster Yuki']);

        $this->assertSame(['Stiker Neko'], $this->listedNames(['category_id' => $stickers->id]));
        $this->assertSame([], $this->listedNames(['category_id' => 987654]), 'Kategori tak dikenal: kosong, bukan galat.');
    }

    public function test_search_matches_the_product_name_only_and_escapes_like_wildcards(): void
    {
        foreach (['100% Katun', '100 Persen', 'Kaos_Putih', 'KaosXPutih', 'Stiker A\\B'] as $name) {
            [, $variant] = $this->makeStorefrontProduct(['name' => $name]);
        }

        $this->assertSame(['100% Katun'], $this->listedNames(['q' => 'katun']), 'Sebagian nama, tanpa peduli huruf besar.');
        $this->assertSame(['100% Katun'], $this->listedNames(['q' => '100%']));
        $this->assertSame(['100% Katun'], $this->listedNames(['q' => '%']), '% adalah huruf biasa, bukan "apa saja".');
        $this->assertSame(['Kaos_Putih'], $this->listedNames(['q' => 'Kaos_']));
        $this->assertSame(['Kaos_Putih'], $this->listedNames(['q' => '_']), '_ adalah huruf biasa, bukan "satu huruf apa saja".');
        $this->assertSame(['Stiker A\\B'], $this->listedNames(['q' => '\\']));
        $this->assertSame(['Stiker A\\B'], $this->listedNames(['q' => 'A\\B']));
        $this->assertEqualsCanonicalizing(['100% Katun', '100 Persen'], $this->listedNames(['q' => ' 100 ']), 'Spasi tepi dibuang.');

        // SKU, nama varian dan nama seller bukan bahan pencarian publik.
        $this->assertSame([], $this->listedNames(['q' => $variant->sku]));
        $this->assertSame([], $this->listedNames(['q' => 'Standard']));
        $this->assertSame([], $this->listedNames(['q' => $variant->product->artist->name]));
    }

    public function test_a_slug_that_cannot_exist_is_simply_not_found(): void
    {
        $this->makeStorefrontProduct(['name' => 'Satu']);

        foreach (['SATU', 'satu%20x', "satu'--", '..%2Fconfig', str_repeat('a', 161)] as $slug) {
            $this->assertProblem($this->getJson("/api/v1/storefront/products/{$slug}"), 404, 'not_found');
        }
    }

    // ------------------------------------------------------------------
    // Gerbang: saklar fitur, limiter, token
    // ------------------------------------------------------------------

    public function test_list_detail_and_categories_answer_503_while_the_feature_is_off(): void
    {
        $this->buildStorefrontWorld();
        $this->disableCustomerCommerce();

        foreach (['/storefront/products', '/storefront/products/gantungan-kunci-sakura', '/storefront/categories'] as $uri) {
            $response = $this->getJson("/api/v1{$uri}");

            $this->assertProblem($response, 503, 'customer_commerce_disabled');
            $response->assertHeader('Retry-After', '3600');
            $this->assertStringNotContainsString('Sakura', $response->getContent());
        }

        // Konfigurasi publik tetap menjawab supaya aplikasi tahu toko sedang tutup.
        $this->getJson('/api/v1/storefront/config')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_catalogue_routes_share_the_storefront_rate_limiter(): void
    {
        config(['customer_commerce.rate_limits.storefront.per_ip' => 2]);
        $this->makeStorefrontProduct(['name' => 'Satu']);

        $this->listing()->assertOk();
        $this->getJson('/api/v1/storefront/categories')->assertOk();
        $this->assertProblem($this->getJson('/api/v1/storefront/products/satu'), 429, 'too_many_requests');
    }

    public function test_any_token_is_accepted_but_ignored(): void
    {
        $sellerA = Artist::factory()->create(['name' => 'Seller A']);
        $sellerB = Artist::factory()->create(['name' => 'Seller B']);
        $this->makeStorefrontProduct(['name' => 'Milik A', 'artist_id' => $sellerA->id]);
        $this->makeStorefrontProduct(['name' => 'Milik B', 'artist_id' => $sellerB->id]);
        $expected = ['Milik A', 'Milik B'];

        [, $customerToken] = $this->customerWithToken();
        // Staf yang terikat ke seller A: di API staf ia hanya melihat produknya sendiri, tetapi
        // etalase publik tidak boleh menyempit (atau melebar) mengikuti siapa yang bertanya.
        $scopedStaff = User::factory()->create(['role' => 'admin', 'artist_id' => $sellerA->id]);

        foreach ([$customerToken, $this->issueStaffToken($scopedStaff), 'bukan-token-sungguhan'] as $token) {
            $this->bearer($token);

            $this->assertEqualsCanonicalizing($expected, $this->listedNames(['sort' => 'name']));
            $this->getJson('/api/v1/storefront/products/milik-b')->assertOk();
            $this->assertCount(2, $this->getJson('/api/v1/storefront/categories')->assertOk()->json('data'));
        }

        $this->withoutBearer();
        $this->assertEqualsCanonicalizing($expected, $this->listedNames());
    }

    // ------------------------------------------------------------------
    // Kinerja
    // ------------------------------------------------------------------

    public function test_the_list_and_the_detail_do_not_run_a_query_per_row(): void
    {
        $count = function (string $uri): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        [$first] = $this->makeStorefrontProduct(['name' => 'Produk 1']);
        $this->listing()->assertOk(); // pemanasan: cache pengaturan (saklar fitur, mode data)

        $listOne = $count('/api/v1/storefront/products?per_page=50');
        $categoriesOne = $count('/api/v1/storefront/categories');
        $detailOne = $count('/api/v1/storefront/products/produk-1');

        // 19 produk lagi, masing-masing dengan seller dan kategori sendiri, dan 8 varian lagi di produk pertama.
        for ($i = 2; $i <= 20; $i++) {
            $this->makeStorefrontProduct(['name' => "Produk {$i}"]);
        }
        for ($i = 1; $i <= 8; $i++) {
            $this->addStorefrontVariant($first, "Varian {$i}", 10000 + $i, 3);
        }

        $this->assertCount(20, $this->listing(['per_page' => 50])->json('data'));
        $this->assertSame($listOne, $count('/api/v1/storefront/products?per_page=50'), 'Daftar: jumlah query tidak tumbuh mengikuti jumlah produk.');
        $this->assertSame($categoriesOne, $count('/api/v1/storefront/categories'));
        $this->assertSame($detailOne, $count('/api/v1/storefront/products/produk-1'), 'Detail: jumlah query tidak tumbuh mengikuti jumlah varian.');
        $this->assertLessThanOrEqual(6, $listOne);
        $this->assertLessThanOrEqual(6, $detailOne);
    }
}
