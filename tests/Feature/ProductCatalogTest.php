<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Support\Code128;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 049 — katalog varian per penjual (PDF dibangun SPA dari JSON ini, Excel dari builder yang sama).
 * Yang dijaga: pengelompokan/urutan, pengecualian (nonaktif/terhapus), filter yang sama dengan
 * daftar produk, TIDAK ADA harga modal/stok di keluaran, pemisahan DEMO/LIVE, identitas toko,
 * gerbang menu `products`, batas ukuran, dan jumlah query yang tetap.
 */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function product(string $seller, string $category, string $name, array $variants, array $attrs = []): Product
    {
        $artist = Artist::where('name', $seller)->first() ?? Artist::factory()->create(['name' => $seller]);
        $cat = Category::where('name', $category)->first() ?? Category::factory()->create(['name' => $category]);
        $product = Product::factory()->create(array_merge(['artist_id' => $artist->id, 'category_id' => $cat->id, 'name' => $name], $attrs));
        foreach ($variants as $i => $v) {
            $product->variants()->create(array_merge([
                'sku' => $product->code_prefix.'-'.($i + 1),
                'variant_name' => "Var {$i}",
                'sell_price' => 20000,
                'cost_price' => 777,
                'current_stock' => 5,
                'is_active' => true,
            ], $v));
        }

        return $product;
    }

    private function catalog(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/catalog'.($query ? "?{$query}" : ''));
    }

    private function setSetting(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string', 'group' => 'receipt']);
    }

    // --- akses -------------------------------------------------------------

    public function test_a_role_without_the_products_menu_is_refused_server_side(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->catalog()->assertForbidden();
    }

    public function test_owner_admin_and_inventory_can_read_the_catalog(): void
    {
        foreach (['owner', 'admin', 'inventory'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');

            $this->catalog()->assertOk();
        }
    }

    // --- pengelompokan, urutan, pengecualian ------------------------------------

    public function test_variants_are_grouped_by_seller_alphabetically_and_ordered_by_category_product_variant(): void
    {
        $this->product('Zeta Seller', 'Sticker', 'Zed', [['variant_name' => 'B']]);
        $this->product('Alpha Seller', 'Sticker', 'Beta', [['variant_name' => 'Y'], ['variant_name' => 'X']]);
        $this->product('Alpha Seller', 'Keychain', 'Omega', [['variant_name' => 'Q']]);
        $this->product('Alpha Seller', 'Keychain', 'Alpha', [['variant_name' => 'Z']]);

        $res = $this->catalog()->assertOk();

        $this->assertSame(['Alpha Seller', 'Zeta Seller'], $res->json('sellers.*.name'));
        $this->assertSame(
            ['Keychain|Alpha|Z', 'Keychain|Omega|Q', 'Sticker|Beta|X', 'Sticker|Beta|Y'],
            collect($res->json('sellers.0.variants'))->map(fn ($v) => "{$v['category_name']}|{$v['product_name']}|{$v['variant_name']}")->all()
        );
        $this->assertSame(['sellers' => 2, 'variants' => 5], $res->json('totals'));
    }

    public function test_ordering_ignores_letter_case(): void
    {
        $this->product('Alpha Seller', 'keychain', 'zebra', [['variant_name' => 'b'], ['variant_name' => 'A']]);
        $this->product('Alpha Seller', 'Keychain', 'Apple', [['variant_name' => 'm']]);

        $names = collect($this->catalog()->assertOk()->json('sellers.0.variants'))->map(fn ($v) => "{$v['product_name']}|{$v['variant_name']}")->all();

        $this->assertSame(['Apple|m', 'zebra|A', 'zebra|b'], $names); // 'keychain' dan 'Keychain' satu kategori urutan
    }

    public function test_each_variant_carries_the_required_fields_and_a_barcode_of_its_exact_code(): void
    {
        $p = $this->product('Alpha Seller', 'Keychain', 'MCYT', [['variant_name' => 'Slippery 5cm', 'sell_price' => 20000]]);
        $sku = $p->variants()->first()->sku;

        $v = $this->catalog()->assertOk()->json('sellers.0.variants.0');

        $this->assertSame(['variant_id', 'category_name', 'product_name', 'variant_name', 'code', 'sell_price', 'image_url', 'barcode'], array_keys($v));
        $this->assertSame($sku, $v['code']);
        $this->assertSame('20000.00', $v['sell_price']);
        $this->assertSame(['type' => 'code128', 'pattern' => Code128::pattern($sku), 'text' => $sku], $v['barcode']);
    }

    public function test_a_variant_whose_code_cannot_be_barcoded_is_still_listed_with_a_null_barcode(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['sku' => 'セラー-1', 'variant_name' => 'Jepang'], ['variant_name' => 'Biasa']]);

        $byName = collect($this->catalog()->assertOk()->json('sellers.0.variants'))->keyBy('variant_name');

        $this->assertNull($byName['Jepang']['barcode']);
        $this->assertSame('セラー-1', $byName['Jepang']['code']); // kode terbaca tetap ada
        $this->assertNotNull($byName['Biasa']['barcode']);
    }

    public function test_inactive_and_deleted_products_variants_and_empty_sellers_are_excluded(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'Live', [['variant_name' => 'Keep'], ['variant_name' => 'Inactive', 'is_active' => false]]);
        $this->product('Beta Seller', 'Keychain', 'Off', [['variant_name' => 'Hidden']], ['is_active' => false]);
        $gone = $this->product('Gamma Seller', 'Keychain', 'Gone', [['variant_name' => 'Deleted']]);
        $gone->variants()->first()->delete();
        $deleted = $this->product('Delta Seller', 'Keychain', 'Trashed', [['variant_name' => 'Trashed Var']]);
        $deleted->delete();

        $res = $this->catalog()->assertOk();

        $this->assertSame(['Alpha Seller'], $res->json('sellers.*.name'));
        $this->assertSame(['Keep'], $res->json('sellers.0.variants.*.variant_name'));
    }

    public function test_a_soft_deleted_seller_with_active_products_still_shows_its_name(): void
    {
        $this->product('Retired Seller', 'Keychain', 'Still Sold', [['variant_name' => 'One']]);
        Artist::where('name', 'Retired Seller')->first()->delete();

        $this->assertSame(['Retired Seller'], $this->catalog()->assertOk()->json('sellers.*.name'));
    }

    public function test_a_variant_without_picture_uses_the_product_picture_then_null(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'WithPic', [['variant_name' => 'Own', 'image_path' => 'variants/own.jpg'], ['variant_name' => 'Fallback']], ['image_path' => 'products/parent.jpg']);
        $this->product('Alpha Seller', 'Keychain', 'NoPic', [['variant_name' => 'None']]);

        $byName = collect($this->catalog()->json('sellers.0.variants'))->keyBy('variant_name');

        $this->assertStringContainsString('variants/own.jpg', $byName['Own']['image_url']);
        $this->assertStringContainsString('products/parent.jpg', $byName['Fallback']['image_url']);
        $this->assertNull($byName['None']['image_url']);
    }

    // --- filter sama dengan daftar produk ----------------------------------------

    public function test_search_seller_and_category_filters_behave_like_the_products_list(): void
    {
        $a = $this->product('Alpha Seller', 'Keychain', 'MCYT', [['variant_name' => 'Slippery']]);
        $b = $this->product('Beta Seller', 'Sticker', 'Stiker Lucu', [['variant_name' => 'Kecil']]);

        $this->assertSame(['Beta Seller'], $this->catalog('search=kecil')->json('sellers.*.name'));
        $this->assertSame(['Alpha Seller'], $this->catalog("artist_id[]={$a->artist_id}")->json('sellers.*.name'));
        $this->assertSame(['Beta Seller'], $this->catalog("category_id[]={$b->category_id}")->json('sellers.*.name'));
        $this->assertSame(['Alpha Seller', 'Beta Seller'], $this->catalog("artist_id[]={$a->artist_id}&artist_id[]={$b->artist_id}")->json('sellers.*.name'));
        $this->assertSame([], $this->catalog("artist_id[]={$a->artist_id}&category_id[]={$b->category_id}")->json('sellers'));
        $this->assertSame([], $this->catalog('artist_id[]=999999')->json('sellers'));
    }

    // --- tidak ada data internal --------------------------------------------------

    public function test_the_output_never_carries_cost_stock_bom_or_preorder_information(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['variant_name' => 'Slippery', 'cost_price' => 12345, 'current_stock' => 98]], ['is_preorder' => true]);

        $json = strtolower($this->catalog()->assertOk()->getContent());

        foreach (['cost', 'stock', 'bom', 'preorder', 'margin', '12345'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "'{$forbidden}' tidak boleh ada di katalog");
        }
    }

    public function test_demo_variants_never_appear_in_live_and_the_store_name_follows_the_mode(): void
    {
        $this->setSetting('store_name', 'Toko Live');
        $this->setSetting('store_name_demo', 'Toko Demo');
        $this->product('Alpha Seller', 'Keychain', 'LiveProd', [['variant_name' => 'LiveVar']]);
        ModeGate::runAs('demo', fn () => $this->product('Demo Seller', 'Keychain', 'DemoProd', [['variant_name' => 'DemoVar']]));

        $res = $this->catalog()->assertOk();

        $this->assertSame(['Alpha Seller'], $res->json('sellers.*.name'));
        $this->assertSame('Toko Live', $res->json('store.name'));
    }

    // --- identitas toko ----------------------------------------------------------

    public function test_store_identity_is_included_and_unset_lines_are_null(): void
    {
        $this->setSetting('store_name', 'Toko Kita');
        $this->setSetting('store_address', 'Jl. Contoh 1');
        $this->setSetting('store_contact_phone', '021-555');
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[]]);

        $res = $this->catalog()->assertOk();

        $this->assertSame('Toko Kita', $res->json('store.name'));
        $this->assertSame('Jl. Contoh 1', $res->json('store.address'));
        $this->assertSame('021-555', $res->json('store.contact_phone'));
        $this->assertNull($res->json('store.contact_email'));
        $this->assertNull($res->json('store.contact_person'));
        $this->assertNotNull($res->json('generated_at'));
    }

    // --- batas dan kinerja -------------------------------------------------------

    public function test_a_catalog_above_the_ceiling_is_refused_with_a_clear_message(): void
    {
        config(['catalog.max_variants' => 2]);
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[], [], []]);

        $this->catalog()->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_an_empty_catalog_is_ok_with_no_sellers(): void
    {
        $this->catalog()->assertOk()->assertJsonPath('sellers', [])->assertJsonPath('totals', ['sellers' => 0, 'variants' => 0]);
    }

    public function test_query_count_does_not_grow_with_the_number_of_variants(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'First', [[]]);
        $this->catalog()->assertOk(); // pemanasan: cache setelan/lisensi terisi sekali, bukan N+1

        DB::enableQueryLog();
        $this->catalog()->assertOk();
        $small = count(DB::getQueryLog());

        foreach (range(1, 6) as $i) {
            $this->product("Seller {$i}", "Cat {$i}", "Prod {$i}", [[], []]);
        }
        DB::flushQueryLog();
        $this->catalog()->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large, 'penjual/kategori/varian harus dimuat eager, bukan per baris');
    }

    // --- Excel (US2) -------------------------------------------------------------

    /** Bahasa bawaan aplikasi Indonesia; tes yang memeriksa teks Inggris memilihnya secara eksplisit. */
    private function asEnglishUser(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'language' => 'en']), 'sanctum');
    }

    private function workbook(string $query = ''): Spreadsheet
    {
        $res = $this->get('/api/v1/catalog/export'.($query ? "?{$query}" : ''))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';
        file_put_contents($path, $res->getContent());
        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return list<list<mixed>> */
    private function sheetRows(Spreadsheet $workbook): array
    {
        return $workbook->getActiveSheet()->toArray(null, true, false, false);
    }

    private function fakeImage(string $path, int $w = 300, int $h = 200): void
    {
        Storage::disk('public')->put($path, (function () use ($w, $h) {
            ob_start();
            imagejpeg(imagecreatetruecolor($w, $h));

            return (string) ob_get_clean();
        })());
    }

    public function test_the_export_answers_with_an_xlsx_file_named_katalog_produk(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[]]);

        $res = $this->get('/api/v1/catalog/export')->assertOk();

        $this->assertStringContainsString('spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('katalog-produk.xlsx', $res->headers->get('Content-Disposition'));
    }

    public function test_the_workbook_has_the_store_block_then_headers_then_seller_groups_with_real_cell_values(): void
    {
        $this->asEnglishUser();
        $this->setSetting('store_name', 'Toko Kita');
        $this->setSetting('store_address', 'Jl. Contoh 1');
        $this->setSetting('store_contact_phone', '021-555');
        $a = $this->product('Alpha Seller', 'Keychain', 'MCYT', [['variant_name' => 'Slippery', 'sell_price' => 20000]]);
        $this->product('Beta Seller', 'Sticker', 'Stiker Lucu', [['variant_name' => 'Kecil', 'sell_price' => 5500]]);

        $rows = $this->sheetRows($this->workbook());

        $this->assertSame('Toko Kita', $rows[0][0]);
        $this->assertSame('Jl. Contoh 1', $rows[1][0]);
        $this->assertStringContainsString('021-555', $rows[2][0]);
        $this->assertStringContainsString('Generated:', $rows[3][0]);

        $headerIndex = collect($rows)->search(fn ($r) => $r[0] === 'Seller');
        $this->assertSame(['Seller', 'Category', 'Product', 'Variant', 'Code', 'Price', 'Image', 'Barcode'], $rows[$headerIndex]);

        $body = array_slice($rows, $headerIndex + 1);
        $this->assertStringContainsString('Alpha Seller', $body[0][0]);            // judul grup penjual
        $this->assertStringContainsString('1 variants', $body[0][0]);
        $this->assertSame(['Alpha Seller', 'Keychain', 'MCYT', 'Slippery', $a->variants()->first()->sku], array_slice($body[1], 0, 5));
        $this->assertSame(20000.0, (float) $body[1][5]);                            // harga = angka, bukan teks
        $this->assertStringContainsString('Beta Seller', $body[2][0]);
        $this->assertSame(5500.0, (float) $body[3][5]);
    }

    public function test_the_price_cell_is_numeric_with_a_rupiah_number_format(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['sell_price' => 20000]]);
        $workbook = $this->workbook(); // tetap dipegang: Spreadsheet yang terlepas membuat sheet-nya kehilangan induk
        $sheet = $workbook->getActiveSheet();

        $priceRow = collect($this->sheetRows($workbook))->search(fn ($r) => (float) ($r[5] ?? 0) === 20000.0) + 1;

        $this->assertTrue(is_numeric($sheet->getCell("F{$priceRow}")->getValue()));
        $this->assertStringContainsString('Rp', $sheet->getStyle("F{$priceRow}")->getNumberFormat()->getFormatCode());
    }

    public function test_seller_heading_rows_are_merged_and_coloured_and_the_header_is_frozen(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[]]);
        $workbook = $this->workbook();
        $sheet = $workbook->getActiveSheet();

        $mergedRanges = array_values($sheet->getMergeCells());

        $this->assertContains('A1:H1', $mergedRanges);
        $this->assertGreaterThanOrEqual(2, count($mergedRanges)); // nama toko + judul penjual
        $this->assertNotNull($sheet->getFreezePane());
    }

    public function test_pictures_and_barcodes_are_embedded_one_each_per_variant_that_has_them(): void
    {
        Storage::fake('public');
        $this->fakeImage('variants/a.jpg');
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['image_path' => 'variants/a.jpg'], []]); // varian kedua tanpa gambar

        $workbook = $this->workbook();
        $sheet = $workbook->getActiveSheet();

        // 2 barcode + 1 gambar (varian tanpa gambar dan produk tanpa gambar = sel kosong)
        $this->assertSame(3, $sheet->getDrawingCollection()->count());
    }

    public function test_a_variant_that_cannot_be_barcoded_is_still_listed_without_a_barcode_picture(): void
    {
        Storage::fake('public');
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['sku' => 'セラー-1']]);

        $workbook = $this->workbook();

        $this->assertSame(0, $workbook->getActiveSheet()->getDrawingCollection()->count());
        $this->assertContains('セラー-1', array_column($this->sheetRows($workbook), 4));
    }

    public function test_the_workbook_never_carries_cost_stock_or_margin(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['cost_price' => 12345, 'current_stock' => 98]], ['is_preorder' => true]);

        $text = strtolower(json_encode($this->sheetRows($this->workbook())));

        foreach (['cost', 'stock', 'stok', 'bom', 'margin', 'preorder', '12345'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "'{$forbidden}' tidak boleh ada di Excel");
        }
    }

    public function test_headers_follow_the_requesting_users_language(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'language' => 'id']), 'sanctum');
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[]]);

        $rows = $this->sheetRows($this->workbook());

        $this->assertContains('Penjual', array_column($rows, 0));
        $this->assertContains(['Penjual', 'Kategori', 'Produk', 'Varian', 'Kode', 'Harga', 'Gambar', 'Barcode'], $rows);
    }

    public function test_the_export_honours_the_same_filters_as_the_json_catalog(): void
    {
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[]]);
        $b = $this->product('Beta Seller', 'Sticker', 'Stiker Lucu', [[]]);

        $text = json_encode($this->sheetRows($this->workbook("artist_id[]={$b->artist_id}")));

        $this->assertStringContainsString('Beta Seller', $text);
        $this->assertStringNotContainsString('Alpha Seller', $text);
    }

    public function test_the_export_is_refused_when_nothing_qualifies_or_the_catalog_is_too_large_or_the_user_lacks_access(): void
    {
        $this->asEnglishUser();
        $this->get('/api/v1/catalog/export')->assertStatus(422)->assertJsonPath('message', 'There are no active variants to export for the current filters.');

        config(['catalog.max_variants' => 1]);
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [[], []]);
        $this->get('/api/v1/catalog/export')->assertStatus(422);

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->get('/api/v1/catalog/export')->assertForbidden();
    }

    public function test_temporary_image_files_are_removed_after_the_export(): void
    {
        Storage::fake('public');
        $this->fakeImage('variants/a.jpg');
        $this->product('Alpha Seller', 'Keychain', 'MCYT', [['image_path' => 'variants/a.jpg']]);
        $before = glob(sys_get_temp_dir().'/boothpos-catalog-*') ?: [];

        $this->get('/api/v1/catalog/export')->assertOk();

        $this->assertSame($before, glob(sys_get_temp_dir().'/boothpos-catalog-*') ?: []);
    }

    #[\PHPUnit\Framework\Attributes\Group('slow')]
    public function test_a_500_variant_workbook_with_pictures_stays_within_a_sane_memory_budget(): void
    {
        Storage::fake('public');
        foreach (range(0, 9) as $i) {
            // Foto "sungguhan": 1200x900 dengan banyak detail acak, supaya JPEG-nya tidak memampat jadi hampir kosong
            // (gambar polos membuat ukuran berkas dan beban memori terlihat jauh lebih ringan dari kenyataan).
            $image = imagecreatetruecolor(1200, 900);
            mt_srand($i);
            for ($n = 0; $n < 600; $n++) {
                imagefilledrectangle($image, mt_rand(0, 1200), mt_rand(0, 900), mt_rand(0, 1200), mt_rand(0, 900), imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
            ob_start();
            imagejpeg($image, null, 92);
            Storage::disk('public')->put("variants/p{$i}.jpg", (string) ob_get_clean());
            imagedestroy($image);
        }
        foreach (range(1, 50) as $p) {
            $this->product("Seller ".($p % 7), 'Keychain', "Produk {$p}", array_map(fn ($i) => ['image_path' => 'variants/p'.($i % 10).'.jpg'], range(0, 9)));
        }
        $this->assertSame(500, ProductVariant::count());

        memory_reset_peak_usage();
        $res = $this->get('/api/v1/catalog/export')->assertOk();
        $peakMb = memory_get_peak_usage() / 1024 / 1024;

        $this->assertGreaterThan(100_000, strlen($res->getContent()), 'workbook tampak terlalu kecil untuk 500 gambar + 500 barcode');
        $this->assertLessThan(400, $peakMb, sprintf('puncak memori %.0f MB untuk 500 varian bergambar', $peakMb));
        fwrite(STDERR, sprintf("\n[049] 500 varian: puncak memori %.0f MB, ukuran xlsx %.1f MB\n", $peakMb, strlen($res->getContent()) / 1048576));
    }
}
