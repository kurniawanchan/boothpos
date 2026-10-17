<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MasterDataExportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function seedCatalog(): void
    {
        $artist = Artist::factory()->create(['code' => 'RYU', 'name' => 'Ryu Illustration']);
        $category = Category::factory()->create(['code' => 'KY', 'name' => 'Keychain']);
        $product = Product::factory()->create([
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'code_prefix' => 'RYUKYSAK',
            'product_segment' => 'SAK',
            'name' => 'Keychain Sakura',
        ]);
        $product->variants()->create([
            'sku' => 'RYUKYSAK0001',
            'variant_name' => 'Standard',
            'cost_price' => 10000,
            'sell_price' => 25000,
            'current_stock' => 12,
        ]);
    }

    /**
     * Berkas benar-benar dibaca ulang lewat PhpSpreadsheet — bukan sekadar
     * memeriksa status 200 dan panjang byte, karena export yang rusak tetap
     * menghasilkan berkas berukuran non-nol (bug itu pernah terjadi di
     * ReportController::export).
     */
    private function readDownload(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);

        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            $sheets[$worksheet->getTitle()] = $worksheet->toArray();
        }

        unlink($path);

        return $sheets;
    }

    public function test_products_export_is_a_readable_file_with_canonical_sheet_name(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $sheets = $this->readDownload('/api/v1/exports/products');

        $this->assertArrayHasKey(MasterDataSheets::PRODUCTS, $sheets);

        $rows = $sheets[MasterDataSheets::PRODUCTS];

        $this->assertSame(MasterDataSheets::exportHeadings(MasterDataSheets::PRODUCTS), $rows[0]);

        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('RYUKYSAK0001', $row['sku']);
        $this->assertSame('RYU', $row['artist_code']);
        $this->assertSame('KY', $row['category_code']);
        $this->assertSame('Keychain Sakura', $row['product_name']);
        $this->assertSame('Standard', $row['variant_name']);
        $this->assertEquals(25000, $row['sell_price']);
    }

    /**
     * Spec 041 (FR-001..FR-004, FR-007): susunan kolom ekspor produk. Urutan
     * dicek persis — kolom yang bergeser satu posisi akan lolos pemeriksaan
     * "ada atau tidak" saja.
     */
    public function test_products_export_uses_the_export_layout_in_order(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];

        $this->assertSame([
            'sku', 'code', 'artist_code', 'artist_name', 'category_code', 'category_name',
            'product_name', 'variant_name', 'item_label', 'description', 'total_stock_available',
            'cost_price', 'sell_price', 'profit_margin', 'low_stock_alert',
        ], $rows[0]);
    }

    /**
     * Spec 041 (FR-003): kedelapan kolom yang dibuang tidak boleh muncul lagi
     * di ekspor — dan tetap ada di impor (dijaga test impor terpisah).
     */
    public function test_products_export_drops_the_removed_columns(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];

        foreach ([
            'product_segment', 'is_preorder', 'preorder_eta', 'product_is_active',
            'variant_is_active', 'initial_stock', 'image_filename', 'variant_image_filename',
        ] as $removed) {
            $this->assertNotContains($removed, $rows[0]);
        }
    }

    /**
     * Spec 041 (US1, US2, US3): nilai turunan dihitung di server dari data
     * yang tersimpan, bukan dari input klien.
     */
    public function test_products_export_derived_values_are_computed_on_the_server(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('Ryu Illustration', $row['artist_name']);
        $this->assertSame('Keychain', $row['category_name']);
        $this->assertSame('SAK0001', $row['code']);
        $this->assertSame('SAK0001 - Keychain Standard', $row['item_label']);
        $this->assertEquals(15000, $row['profit_margin']);
    }

    /**
     * Spec 046 (research Decision 10, pilihan (a)): `code` ekspor = bagian SKU setelah
     * "ART-CA-", jadi segmen dengan tanda hubung dan nomor tanpa nol di depan utuh.
     */
    public function test_products_export_code_is_the_sku_after_the_artist_and_category(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $product = Product::firstOrFail();
        $product->update(['code_prefix' => 'RYU-KY-SH-K', 'product_segment' => 'SH-K']);
        $product->variants()->create([
            'sku' => 'RYU-KY-SH-K-12',
            'variant_name' => 'Panjang',
            'cost_price' => 1000,
            'sell_price' => 2000,
            'current_stock' => 0,
        ]);

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];
        $row = $this->rowBySku($rows, 'RYU-KY-SH-K-12');

        $this->assertSame('SH-K-12', $row['code']);
        $this->assertSame('SH-K-12 - Keychain Panjang', $row['item_label']);
    }

    /**
     * Spec 041 (FR-005 edge case) + spec 046: SKU lama tanpa tanda hubung (yang
     * tidak diawali "ART-CA-") dipakai utuh sebagai `code`.
     */
    public function test_products_export_short_sku_uses_the_whole_sku_as_code(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $product = Product::firstOrFail();
        $product->variants()->create([
            'sku' => 'AB1',
            'variant_name' => 'Pendek',
            'cost_price' => 1000,
            'sell_price' => 2000,
            'current_stock' => 0,
        ]);

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];
        $row = $this->rowBySku($rows, 'AB1');

        $this->assertSame('AB1', $row['code']);
        $this->assertSame('AB1 - Keychain Pendek', $row['item_label']);
    }

    /**
     * Spec 041 (US3, FR-007): margin boleh negatif dan tetap dalam format uang.
     */
    public function test_products_export_profit_margin_can_be_negative(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $product = Product::firstOrFail();
        $product->variants()->create([
            'sku' => 'RYUKYSAK0002',
            'variant_name' => 'Mini',
            'cost_price' => 10000,
            'sell_price' => 9500,
            'current_stock' => 0,
        ]);

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];
        $row = $this->rowBySku($rows, 'RYUKYSAK0002');

        $this->assertEquals(-500, $row['profit_margin']);
    }

    /**
     * Spec 041 (FR-011, option A): template dan impor produk TIDAK berubah.
     * Daftar impor PRODUCTS tetap 17 kolom, termasuk kolom yang dibuang dari
     * ekspor.
     */
    public function test_products_import_headings_are_unchanged_by_the_export_layout(): void
    {
        $headings = MasterDataSheets::headings(MasterDataSheets::PRODUCTS);

        $this->assertCount(17, $headings);
        $this->assertContains('initial_stock', $headings);
        $this->assertContains('image_filename', $headings);
        $this->assertContains('is_preorder', $headings);
    }

    /**
     * Spec 041 (US5, FR-012): stok varian ditulis apa adanya di kolom
     * total_stock_available, termasuk nol (bukan kosong).
     */
    public function test_products_export_total_stock_available_is_the_variant_stock_and_zero_is_written(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog(); // RYUKYSAK0001 dengan current_stock 12
        $product = Product::firstOrFail();
        $product->variants()->create([
            'sku' => 'RYUKYSAK0002',
            'variant_name' => 'Habis',
            'cost_price' => 1000,
            'sell_price' => 2000,
            'current_stock' => 0,
        ]);

        $rows = $this->readDownload('/api/v1/exports/products')[MasterDataSheets::PRODUCTS];

        $this->assertEquals(12, $this->rowBySku($rows, 'RYUKYSAK0001')['total_stock_available']);
        $this->assertEquals(0, $this->rowBySku($rows, 'RYUKYSAK0002')['total_stock_available']);
    }

    /**
     * Produk tambahan untuk uji filter (spec 041, US6). $variants berisi
     * [sku, variant_name] — harga/stok seragam supaya fokus uji tetap pada filter.
     */
    private function addProduct(string $name, Artist $artist, Category $category, string $prefix, array $variants): Product
    {
        $product = Product::factory()->create([
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'code_prefix' => $prefix,
            'product_segment' => 'SEG',
            'name' => $name,
        ]);

        foreach ($variants as [$sku, $variantName]) {
            $product->variants()->create([
                'sku' => $sku,
                'variant_name' => $variantName,
                'cost_price' => 1000,
                'sell_price' => 2000,
                'current_stock' => 3,
            ]);
        }

        return $product;
    }

    /** @return array<int, string> nama produk dari baris ekspor (tanpa header) */
    private function exportedProductNames(string $query): array
    {
        $rows = $this->readDownload('/api/v1/exports/products'.$query)[MasterDataSheets::PRODUCTS];
        $header = array_shift($rows);

        return array_values(array_unique(array_map(fn ($cells) => array_combine($header, $cells)['product_name'], $rows)));
    }

    /** @return array<int, string> SKU dari baris ekspor (tanpa header) */
    private function exportedSkus(string $query): array
    {
        $rows = $this->readDownload('/api/v1/exports/products'.$query)[MasterDataSheets::PRODUCTS];
        $header = array_shift($rows);

        return array_map(fn ($cells) => array_combine($header, $cells)['sku'], $rows);
    }

    /** T015 — pencarian nama produk hanya membawa produk itu. */
    public function test_products_export_search_by_product_name_exports_only_that_product(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $artist = Artist::where('code', 'RYU')->firstOrFail();
        $category = Category::where('code', 'KY')->firstOrFail();
        $this->addProduct('Gantungan Mawar', $artist, $category, 'RYUKYMWR', [['RYUKYMWR0001', 'Standard']]);

        $names = $this->exportedProductNames('?search=Sakura');

        $this->assertSame(['Keychain Sakura'], $names);
    }

    /** T016 — pencarian sku varian ikut membawa SEMUA varian produknya (mirror layar). */
    public function test_products_export_search_by_variant_sku_includes_sibling_variants(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $artist = Artist::where('code', 'RYU')->firstOrFail();
        $category = Category::where('code', 'KY')->firstOrFail();
        $this->addProduct('Gantungan Mawar', $artist, $category, 'RYUKYMWR', [
            ['RYUKYMWR0001', 'Standard'],
            ['RYUKYMWR0002', 'Besar'],
        ]);

        $skus = $this->exportedSkus('?search=MWR0002');

        sort($skus);
        $this->assertSame(['RYUKYMWR0001', 'RYUKYMWR0002'], $skus);
    }

    /** T017 — filter artis, kategori multi-pilih (OR dalam satu jenis), dan kombinasi (AND antar jenis). */
    public function test_products_export_artist_category_and_combined_filters(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $ryu = Artist::where('code', 'RYU')->firstOrFail();
        $ryuKy = Category::where('code', 'KY')->firstOrFail();
        $other = Artist::factory()->create(['code' => 'BBB', 'name' => 'Bebek Biru']);
        $gantungan = Category::factory()->create(['code' => 'GL', 'name' => 'Gelang']);

        $this->addProduct('Gantungan Mawar', $ryu, $gantungan, 'RYUGLMWR', [['RYUGLMWR0001', 'Standard']]);
        $this->addProduct('Gelang Bebek', $other, $gantungan, 'BBBGLBBK', [['BBBGLBBK0001', 'Standard']]);
        $this->addProduct('Gantungan Bebek', $other, $ryuKy, 'BBBKYBBK', [['BBBKYBBK0001', 'Standard']]);

        // Artis saja: hanya produk RYU.
        $this->assertEqualsCanonicalizing(
            ['Keychain Sakura', 'Gantungan Mawar'],
            $this->exportedProductNames('?artist_id[]='.$ryu->id),
        );

        // Dua kategori: OR di dalam satu jenis.
        $this->assertEqualsCanonicalizing(
            ['Keychain Sakura', 'Gantungan Mawar', 'Gelang Bebek', 'Gantungan Bebek'],
            $this->exportedProductNames('?category_id[]='.$ryuKy->id.'&category_id[]='.$gantungan->id),
        );

        // Kombinasi cari + artis: AND antar jenis.
        $this->assertSame(
            ['Gelang Bebek'],
            $this->exportedProductNames('?search=Gelang&artist_id[]='.$other->id),
        );
    }

    /** T018 — tanpa filter semua varian; tanpa cocok = hanya header; tidak terpotong halaman. */
    public function test_products_export_without_filter_all_variants_no_match_header_only_and_no_page_cap(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $artist = Artist::where('code', 'RYU')->firstOrFail();
        $category = Category::where('code', 'KY')->firstOrFail();

        // 30 produk tambahan → melewati batas halaman daftar (25).
        for ($i = 1; $i <= 30; $i++) {
            $this->addProduct(sprintf('Produk %02d', $i), $artist, $category, sprintf('RYUKYP%02d', $i), [[sprintf('RYUKYP%02d0001', $i), 'Standard']]);
        }

        $this->assertCount(31, $this->exportedSkus(''));
        // Pencarian 'Produk' hanya mengenai 30 produk tambahan, bukan 'Keychain Sakura'.
        $this->assertCount(30, $this->exportedSkus('?search=Produk'));

        $this->assertSame([], $this->exportedSkus('?search=TIDAK_ADA_SAMA_SEKALI'));
    }

    /** T019 — daftar dan ekspor memberi himpunan produk yang sama untuk filter yang sama. */
    public function test_products_list_and_export_return_the_same_products_for_the_same_filters(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();
        $artist = Artist::where('code', 'RYU')->firstOrFail();
        $category = Category::where('code', 'KY')->firstOrFail();
        $this->addProduct('Gantungan Mawar', $artist, $category, 'RYUKYMWR', [['RYUKYMWR0001', 'Standard']]);

        $query = '?search=Gantungan';

        $list = $this->getJson('/api/v1/products'.$query)->assertOk()->json('data');
        $listNames = array_values(array_unique(array_column($list, 'name')));

        $this->assertEqualsCanonicalizing($listNames, $this->exportedProductNames($query));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowBySku(array $rows, string $sku): array
    {
        $header = $rows[0];
        foreach (array_slice($rows, 1) as $cells) {
            $row = array_combine($header, $cells);
            if ($row['sku'] === $sku) {
                return $row;
            }
        }

        $this->fail("Baris dengan sku {$sku} tidak ditemukan di ekspor.");
    }

    public function test_stock_export_lists_every_variant_with_its_current_stock(): void
    {
        $this->actingAsRole('inventory');
        $this->seedCatalog();

        $sheets = $this->readDownload('/api/v1/exports/stock');

        $rows = $sheets[MasterDataSheets::STOCK];
        $this->assertSame(['sku', 'current_stock', 'reason'], $rows[0]);

        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('RYUKYSAK0001', $row['sku']);
        $this->assertEquals(12, $row['current_stock']);
    }

    public function test_artists_and_categories_export_use_their_own_columns(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $artists = $this->readDownload('/api/v1/exports/artists')[MasterDataSheets::ARTISTS];
        $this->assertSame(MasterDataSheets::headings(MasterDataSheets::ARTISTS), $artists[0]);
        $this->assertSame('RYU', array_combine($artists[0], $artists[1])['code']);

        $categories = $this->readDownload('/api/v1/exports/categories')[MasterDataSheets::CATEGORIES];
        $this->assertSame(MasterDataSheets::headings(MasterDataSheets::CATEGORIES), $categories[0]);
        $this->assertSame('KY', array_combine($categories[0], $categories[1])['code']);
    }

    public function test_export_is_gated_to_master_data_roles(): void
    {
        $this->actingAsRole('cashier');
        $this->seedCatalog();

        $this->getJson('/api/v1/exports/products')->assertStatus(403);
        $this->getJson('/api/v1/exports/stock')->assertStatus(403);
    }

    public function test_unknown_export_entity_is_rejected_by_the_route(): void
    {
        $this->actingAsRole('owner');

        $this->getJson('/api/v1/exports/customers')->assertStatus(404);
    }

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/v1/exports/products')->assertStatus(401);
    }
}
