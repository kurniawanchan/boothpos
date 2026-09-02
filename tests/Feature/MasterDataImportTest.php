<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\MasterDataImportService;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class MasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] berkas sementara yang dibuat test, dibersihkan di tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function enableMultiArtist(): void
    {
        Setting::updateOrCreate(
            ['key' => 'multi_artist_enabled'],
            ['value' => '1', 'type' => 'boolean', 'group' => 'licensing'],
        );
    }

    /**
     * Membuat berkas .xlsx SUNGGUHAN lalu membungkusnya sebagai unggahan.
     * Sengaja bukan UploadedFile::fake()->create(): berkas palsu itu kosong,
     * jadi tidak akan pernah membuktikan pembacaan sheet, deteksi MIME,
     * maupun pemeriksaan struktur Xlsx benar-benar jalan.
     *
     * @param  array<string, array{headings?: array, rows?: array}>  $sheets
     */
    private function workbook(array $sheets, string $originalName = 'impor.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $definition) {
            $canonical = MasterDataSheets::canonicalName($title);
            $headings = $definition['headings'] ?? ($canonical ? MasterDataSheets::headings($canonical) : []);
            $rows = $definition['rows'] ?? [];

            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle($title);
            $worksheet->fromArray($headings, null, 'A1');

            $ordered = array_map(
                fn (array $row) => array_map(fn (string $heading) => $row[$heading] ?? null, $headings),
                $rows,
            );

            if ($ordered !== []) {
                $worksheet->fromArray($ordered, null, 'A2');
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'boothpos-import').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $originalName, null, null, true);
    }

    private function postImport(UploadedFile $file, array $extra = [])
    {
        return $this->post(
            '/api/v1/imports/master-data',
            array_merge(['file' => $file], $extra),
            ['Accept' => 'application/json'],
        );
    }

    private function fullCatalogWorkbook(): UploadedFile
    {
        return $this->workbook([
            'artists' => ['rows' => [
                ['code' => 'RYU', 'name' => 'Ryu Illustration', 'contact_email' => 'ryu@example.test', 'is_active' => 1],
            ]],
            'categories' => ['rows' => [
                ['code' => 'KY', 'name' => 'Keychain', 'display_order' => 1, 'is_active' => 1],
            ]],
            'products' => ['rows' => [
                [
                    'artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK',
                    'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard',
                    'cost_price' => 10000, 'sell_price' => 25000, 'low_stock_alert' => 5,
                    'initial_stock' => 20,
                ],
            ]],
        ]);
    }

    // =================================================================
    // TEMPLATE (F15.3)
    // =================================================================

    public function test_template_contains_all_four_sheets_with_the_right_headings(): void
    {
        $this->actingAsRole('owner');

        $response = $this->get('/api/v1/imports/master-data/template');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);

        $this->assertSame(MasterDataSheets::ORDER, $spreadsheet->getSheetNames());

        foreach (MasterDataSheets::ORDER as $sheet) {
            $rows = $spreadsheet->getSheetByName($sheet)->toArray();
            $this->assertSame(MasterDataSheets::headings($sheet), $rows[0], "Judul kolom sheet '{$sheet}' tidak cocok.");
            $this->assertCount(2, $rows, "Sheet '{$sheet}' harus punya satu baris contoh.");
        }
    }

    /**
     * Alur pertama pemilik toko non-teknis: unduh template, isi, unggah.
     * Template yang baris contohnya sendiri gagal validasi adalah paper cut
     * paling mahal yang bisa dipunyai fitur ini — jadi diuji langsung.
     */
    public function test_the_shipped_template_imports_as_is(): void
    {
        $this->actingAsRole('owner');

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, $this->get('/api/v1/imports/master-data/template')->streamedContent());

        $this->postImport(new UploadedFile($path, 'template-impor-master-data.xlsx', null, null, true))
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseHas('artists', ['code' => 'RYU']);
        $this->assertDatabaseHas('categories', ['code' => 'KY']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'RYUKYSAK0001', 'current_stock' => 20]);

        // T054 (User Story 4) — sheet 'roles'/'users' ikut diperluas ke
        // template ini; baris contohnya harus saling konsisten seperti
        // pasangan artists/stock di atas.
        $this->assertDatabaseHas('roles', ['name' => 'Kasir Cabang Contoh']);
        $this->assertDatabaseHas('users', ['username' => 'contoh.kasir']);
    }

    public function test_template_is_gated_to_master_data_roles(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/imports/master-data/template')->assertStatus(403);
    }

    // =================================================================
    // OTORISASI & VALIDASI BERKAS
    // =================================================================

    public function test_import_is_gated_to_master_data_roles(): void
    {
        $this->actingAsRole('cashier');

        $this->postImport($this->fullCatalogWorkbook())
            ->assertStatus(403)
            // Pesannya berbahasa Indonesia seperti dua endpoint
            // impor/ekspor sebelahnya, bukan "This action is unauthorized."
            ->assertJsonPath('message', 'Hanya owner/admin/inventory yang dapat mengimpor data master.');

        $this->assertSame(0, Artist::count());
    }

    public function test_inventory_role_may_import(): void
    {
        $this->actingAsRole('inventory');
        $this->enableMultiArtist();

        $this->postImport($this->fullCatalogWorkbook())->assertOk();
    }

    public function test_a_renamed_text_file_is_rejected_even_with_an_xlsx_extension(): void
    {
        $this->actingAsRole('owner');

        $path = tempnam(sys_get_temp_dir(), 'fake').'.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, "ini bukan spreadsheet\n");

        $response = $this->postImport(new UploadedFile($path, 'jahat.xlsx', null, null, true));

        $response->assertStatus(422);
        $this->assertSame(0, Artist::count());
    }

    public function test_a_zip_that_is_not_a_workbook_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $path = tempnam(sys_get_temp_dir(), 'zip').'.xlsx';
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('halo.txt', 'bukan workbook');
        $zip->close();

        $this->postImport(new UploadedFile($path, 'jahat.xlsx', null, null, true))->assertStatus(422);
    }

    public function test_import_requires_authentication(): void
    {
        $this->postJson('/api/v1/imports/master-data')->assertStatus(401);
    }

    // =================================================================
    // JALUR BAHAGIA
    // =================================================================

    public function test_happy_path_creates_artists_categories_products_and_stock(): void
    {
        $this->actingAsRole('owner');
        $this->enableMultiArtist();

        $response = $this->postImport($this->fullCatalogWorkbook());

        $response->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('sheets.artists.created', 1)
            ->assertJsonPath('sheets.categories.created', 1)
            ->assertJsonPath('sheets.products.created', 1);

        $this->assertDatabaseHas('artists', ['code' => 'RYU', 'name' => 'Ryu Illustration']);
        $this->assertDatabaseHas('categories', ['code' => 'KY', 'name' => 'Keychain']);

        // Kode produk & SKU dihasilkan SERVER lewat ProductCodeGenerator.
        $this->assertDatabaseHas('products', ['code_prefix' => 'RYUKYSAK', 'name' => 'Keychain Sakura']);
        $this->assertDatabaseHas('product_variants', [
            'sku' => 'RYUKYSAK0001',
            'variant_name' => 'Standard',
            'current_stock' => 20,
        ]);
    }

    public function test_segment_is_derived_from_the_product_name_when_left_blank(): void
    {
        $this->actingAsRole('owner');
        Artist::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['artist_code' => 'RYU', 'category_code' => 'KY', 'product_name' => 'Poster Yuki', 'variant_name' => 'A4', 'sell_price' => 15000],
            ]],
        ]))->assertOk();

        $this->assertDatabaseHas('products', ['code_prefix' => 'RYUKYPOS']);
    }

    public function test_sheets_are_processed_in_dependency_order_regardless_of_physical_order(): void
    {
        $this->actingAsRole('owner');
        $this->enableMultiArtist();

        // Sheet ditulis TERBALIK di dalam berkas: produk dulu, artist dan
        // kategori paling belakang.
        $file = $this->workbook([
            'products' => ['rows' => [
                ['artist_code' => 'ZZZ', 'category_code' => 'ZZ', 'product_segment' => 'AAA', 'product_name' => 'Produk Baru', 'variant_name' => 'Standard', 'sell_price' => 1000],
            ]],
            'categories' => ['rows' => [['code' => 'ZZ', 'name' => 'Kategori Baru']]],
            'artists' => ['rows' => [['code' => 'ZZZ', 'name' => 'Artist Baru']]],
        ]);

        $this->postImport($file)->assertOk()->assertJsonPath('applied', true);

        $this->assertDatabaseHas('products', ['code_prefix' => 'ZZZZZAAA']);
    }

    public function test_sheet_names_are_matched_case_insensitively_and_extras_are_ignored(): void
    {
        $this->actingAsRole('owner');

        $file = $this->workbook([
            'Artists' => ['headings' => MasterDataSheets::headings('artists'), 'rows' => [['code' => 'RYU', 'name' => 'Ryu']]],
            'Catatan Toko' => ['headings' => ['apa saja'], 'rows' => []],
        ]);

        $this->postImport($file)->assertOk()
            ->assertJsonPath('sheets.artists.created', 1)
            ->assertJsonPath('ignored_sheets', ['Catatan Toko']);
    }

    // =================================================================
    // UPSERT
    // =================================================================

    public function test_reimporting_a_corrected_sheet_updates_instead_of_duplicating(): void
    {
        $this->actingAsRole('owner');

        Artist::factory()->create(['code' => 'RYU', 'name' => 'Nama Lama']);
        Category::factory()->create(['code' => 'KY', 'name' => 'Keychain']);

        $rows = [[
            'artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK',
            'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard', 'sell_price' => 25000,
        ]];

        $this->postImport($this->workbook([
            'artists' => ['rows' => [['code' => 'RYU', 'name' => 'Nama Baru']]],
            'products' => ['rows' => $rows],
        ]))->assertOk();

        $this->assertSame('Nama Baru', Artist::where('code', 'RYU')->value('name'));
        $this->assertSame(1, ProductVariant::count());

        // Impor kedua dengan harga yang sudah dikoreksi — tidak boleh
        // menabrak unique sku/code_prefix, dan tidak boleh menggandakan.
        $rows[0]['sell_price'] = 30000;

        $this->postImport($this->workbook([
            'artists' => ['rows' => [['code' => 'RYU', 'name' => 'Nama Baru']]],
            'products' => ['rows' => $rows],
        ]))->assertOk()
            ->assertJsonPath('sheets.artists.updated', 1)
            ->assertJsonPath('sheets.artists.created', 0)
            ->assertJsonPath('sheets.products.updated', 1)
            ->assertJsonPath('sheets.products.created', 0);

        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductVariant::count());
        $this->assertEquals(30000, (float) ProductVariant::first()->sell_price);
    }

    public function test_a_second_variant_of_an_existing_product_is_created_with_a_sequential_sku(): void
    {
        $this->actingAsRole('owner');
        Artist::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);

        $base = ['artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK', 'product_name' => 'Keychain Sakura'];

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                array_merge($base, ['variant_name' => 'Standard', 'sell_price' => 25000]),
                array_merge($base, ['variant_name' => 'Glitter', 'sell_price' => 30000]),
            ]],
        ]))->assertOk()->assertJsonPath('sheets.products.created', 2);

        $this->assertSame(1, Product::count());
        $this->assertSame(
            ['RYUKYSAK0001', 'RYUKYSAK0002'],
            ProductVariant::orderBy('sku')->pluck('sku')->all(),
        );
    }

    public function test_a_row_may_update_an_existing_variant_by_sku(): void
    {
        $this->actingAsRole('owner');
        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'code_prefix' => 'RYUKYSAK', 'product_segment' => 'SAK', 'name' => 'Keychain Sakura',
        ]);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'variant_name' => 'Standard', 'sell_price' => 25000]);

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['sku' => 'RYUKYSAK0001', 'sell_price' => 27500, 'low_stock_alert' => 3],
            ]],
        ]))->assertOk()->assertJsonPath('sheets.products.updated', 1);

        $variant = ProductVariant::first();
        $this->assertEquals(27500, (float) $variant->sell_price);
        $this->assertSame(3, $variant->low_stock_alert);
        // Sel kosong berarti "jangan diubah", bukan "kosongkan".
        $this->assertSame('Standard', $variant->variant_name);
    }

    public function test_a_client_supplied_sku_for_a_new_variant_is_rejected(): void
    {
        $this->actingAsRole('owner');
        Artist::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);

        $response = $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['sku' => 'AKUBIKINSENDIRI', 'artist_code' => 'RYU', 'category_code' => 'KY', 'product_name' => 'Palsu', 'variant_name' => 'Standard', 'sell_price' => 1000],
            ]],
        ]));

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.sheet', 'products')
            ->assertJsonPath('errors.0.row', 2)
            ->assertJsonPath('errors.0.column', 'sku');

        $this->assertSame(0, ProductVariant::count());
    }

    // =================================================================
    // STOK (F15.8) — absolut, dan hanya lewat StockService
    // =================================================================

    public function test_stock_sheet_is_absolute_and_is_applied_as_a_movement(): void
    {
        $this->actingAsRole('owner');
        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'code_prefix' => 'RYUKYSAK', 'product_segment' => 'SAK',
        ]);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'sell_price' => 1000, 'current_stock' => 8]);

        $this->postImport($this->workbook([
            'stock' => ['rows' => [
                ['sku' => 'RYUKYSAK0001', 'current_stock' => 20, 'reason' => 'Stok opname'],
            ]],
        ]))->assertOk()->assertJsonPath('sheets.stock.updated', 1);

        $this->assertSame(20, ProductVariant::first()->current_stock);

        $movement = StockMovement::where('variant_id', ProductVariant::first()->id)->latest('id')->first();
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame(12, $movement->qty_change);   // 20 - 8, bukan +20
        $this->assertSame(8, $movement->stock_before);
        $this->assertSame(20, $movement->stock_after);
        $this->assertStringContainsString(MasterDataImportService::REASON_ADJUSTMENT, $movement->reason);
        $this->assertStringContainsString('Stok opname', $movement->reason);
    }

    public function test_a_stock_row_that_matches_current_stock_writes_no_movement(): void
    {
        $this->actingAsRole('owner');
        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'code_prefix' => 'RYUKYSAK']);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'sell_price' => 1000, 'current_stock' => 8]);

        $this->postImport($this->workbook([
            'stock' => ['rows' => [['sku' => 'RYUKYSAK0001', 'current_stock' => 8]]],
        ]))->assertOk()
            ->assertJsonPath('sheets.stock.unchanged', 1)
            ->assertJsonPath('sheets.stock.updated', 0);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_initial_stock_on_a_new_variant_goes_through_stock_movements(): void
    {
        $this->actingAsRole('owner');
        $this->enableMultiArtist();

        $this->postImport($this->fullCatalogWorkbook())->assertOk();

        $variant = ProductVariant::where('sku', 'RYUKYSAK0001')->firstOrFail();

        $movement = StockMovement::where('variant_id', $variant->id)->firstOrFail();
        $this->assertSame('adjustment', $movement->type);
        $this->assertSame(20, $movement->qty_change);
        $this->assertSame(0, $movement->stock_before);
        $this->assertSame(20, $movement->stock_after);
        $this->assertSame(MasterDataImportService::REASON_INITIAL, $movement->reason);
    }

    public function test_initial_stock_is_refused_for_a_variant_that_already_exists(): void
    {
        $this->actingAsRole('owner');
        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'code_prefix' => 'RYUKYSAK']);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'variant_name' => 'Standard', 'sell_price' => 1000, 'current_stock' => 4]);

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['sku' => 'RYUKYSAK0001', 'initial_stock' => 99],
            ]],
        ]))->assertStatus(422)
            ->assertJsonPath('errors.0.column', 'initial_stock');

        $this->assertSame(4, ProductVariant::first()->current_stock);
    }

    public function test_a_stock_row_for_an_unknown_sku_is_reported_with_its_row_number(): void
    {
        $this->actingAsRole('owner');

        $this->postImport($this->workbook([
            'stock' => ['rows' => [
                ['sku' => 'TIDAKADA0001', 'current_stock' => 5],
            ]],
        ]))->assertStatus(422)
            ->assertJsonPath('errors.0.sheet', 'stock')
            ->assertJsonPath('errors.0.row', 2)
            ->assertJsonPath('errors.0.column', 'sku');
    }

    // SKU varian baru dihasilkan server dan bisa ditebak dari kodenya
    // (RYU + KY + SAK -> RYUKYSAK0001). Kalau sheet products memang membuat
    // varian baru, sheet stock boleh menunjuk SKU itu — penyelesaiannya
    // ditunda sampai sheet products diterapkan.
    public function test_a_stock_row_may_reference_a_sku_created_by_the_products_sheet(): void
    {
        $this->actingAsRole('owner');
        Artist::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK', 'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard', 'sell_price' => 25000],
            ]],
            'stock' => ['rows' => [
                ['sku' => 'RYUKYSAK0001', 'current_stock' => 7],
            ]],
        ]))->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(7, ProductVariant::where('sku', 'RYUKYSAK0001')->value('current_stock'));
        $this->assertEquals(7, StockMovement::sum('qty_change'));
    }

    // ...tapi kalau SKU-nya salah tulis, impor tetap batal seluruhnya dan
    // galatnya tetap menunjuk sheet + nomor baris yang benar.
    public function test_a_mistyped_deferred_sku_still_rolls_the_whole_import_back(): void
    {
        $this->actingAsRole('owner');
        Artist::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK', 'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard', 'sell_price' => 25000],
            ]],
            'stock' => ['rows' => [
                ['sku' => 'RYUKYSAK9999', 'current_stock' => 7],
            ]],
        ]))->assertStatus(422)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('errors.0.sheet', 'stock')
            ->assertJsonPath('errors.0.row', 2)
            ->assertJsonPath('errors.0.column', 'sku');

        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductVariant::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, ActivityLog::count());
    }

    // =================================================================
    // VALIDASI & ROLLBACK
    // =================================================================

    public function test_one_bad_row_rolls_back_every_other_sheet(): void
    {
        $this->actingAsRole('owner');
        $this->enableMultiArtist();

        $file = $this->workbook([
            'artists' => ['rows' => [
                ['code' => 'RYU', 'name' => 'Ryu Illustration'],
                ['code' => 'AKI', 'name' => 'Aki Studio'],
            ]],
            'categories' => ['rows' => [['code' => 'KY', 'name' => 'Keychain']]],
            'products' => ['rows' => [
                ['artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK', 'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard', 'sell_price' => 25000],
                // baris 3: kategori 'ZZ' tidak ada di berkas maupun database
                ['artist_code' => 'RYU', 'category_code' => 'ZZ', 'product_segment' => 'PST', 'product_name' => 'Poster', 'variant_name' => 'A4', 'sell_price' => 15000],
            ]],
        ]);

        $response = $this->postImport($file);

        $response->assertStatus(422)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('errors.0.sheet', 'products')
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonPath('errors.0.column', 'category_code');

        $this->assertStringContainsString("'ZZ'", $response->json('errors.0.message'));

        // Tidak ada satu pun sheet yang tersimpan sebagian.
        $this->assertSame(0, Artist::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, ProductVariant::count());
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_duplicate_codes_inside_one_sheet_are_reported(): void
    {
        $this->actingAsRole('owner');
        $this->enableMultiArtist();

        $this->postImport($this->workbook([
            'artists' => ['rows' => [
                ['code' => 'RYU', 'name' => 'Satu'],
                ['code' => 'RYU', 'name' => 'Dua'],
            ]],
        ]))->assertStatus(422)
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonPath('errors.0.column', 'code');
    }

    public function test_a_non_numeric_price_is_rejected_rather_than_guessed(): void
    {
        $this->actingAsRole('owner');
        Artist::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);

        $this->postImport($this->workbook([
            'products' => ['rows' => [
                ['artist_code' => 'RYU', 'category_code' => 'KY', 'product_name' => 'Poster', 'variant_name' => 'A4', 'sell_price' => 'Rp 15.000'],
            ]],
        ]))->assertStatus(422)
            ->assertJsonPath('errors.0.column', 'sell_price');
    }

    public function test_a_sheet_with_unrecognised_headings_is_reported_once(): void
    {
        $this->actingAsRole('owner');

        $this->postImport($this->workbook([
            'artists' => ['headings' => ['kode', 'nama'], 'rows' => [['kode' => 'RYU', 'nama' => 'Ryu']]],
        ]))->assertStatus(422)
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.row', 1);
    }

    public function test_a_file_without_any_recognised_sheet_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->postImport($this->workbook([
            'Sheet1' => ['headings' => ['a'], 'rows' => [['a' => 1]]],
        ]))->assertStatus(422)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('errors.0.sheet', null);
    }

    // =================================================================
    // LISENSI, PRATINJAU, AUDIT
    // =================================================================

    public function test_import_cannot_bypass_the_pro_license_artist_quota(): void
    {
        $this->actingAsRole('owner');
        // multi_artist_enabled dibiarkan false -> lisensi Pro (1 artist).

        $this->postImport($this->workbook([
            'artists' => ['rows' => [
                ['code' => 'RYU', 'name' => 'Satu'],
                ['code' => 'AKI', 'name' => 'Dua'],
            ]],
        ]))->assertStatus(422)
            ->assertJsonPath('errors.0.sheet', 'artists');

        $this->assertSame(0, Artist::count());
    }

    public function test_dry_run_reports_the_plan_without_writing_anything(): void
    {
        $this->actingAsRole('owner');
        $this->enableMultiArtist();

        $this->postImport($this->fullCatalogWorkbook(), ['dry_run' => 1])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('errors', [])
            ->assertJsonPath('sheets.artists.created', 1)
            ->assertJsonPath('sheets.products.created', 1);

        $this->assertSame(0, Artist::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_import_writes_an_activity_log_entry(): void
    {
        $user = $this->actingAsRole('owner');
        $this->enableMultiArtist();

        $this->postImport($this->fullCatalogWorkbook())->assertOk();

        $log = ActivityLog::where('action', 'imported')->firstOrFail();
        $this->assertSame('MasterDataImport', $log->entity_type);
        $this->assertSame($user->id, $log->user_id);
        $this->assertStringContainsString('impor.xlsx', $log->description);

        // Penyesuaian stok yang dilakukan impor tetap menulis lognya
        // sendiri lewat StockService (F13.4).
        $this->assertSame(1, ActivityLog::where('action', 'stock_adjusted')->count());
    }

    // =================================================================
    // ROUND TRIP EKSPOR -> IMPOR
    // =================================================================

    public function test_a_stock_export_can_be_edited_and_imported_back(): void
    {
        $this->actingAsRole('owner');
        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'code_prefix' => 'RYUKYSAK']);
        $product->variants()->create(['sku' => 'RYUKYSAK0001', 'sell_price' => 1000, 'current_stock' => 3]);

        $exported = tempnam(sys_get_temp_dir(), 'roundtrip').'.xlsx';
        $this->tempFiles[] = $exported;
        file_put_contents($exported, $this->get('/api/v1/exports/stock')->streamedContent());

        // Sunting satu sel, persis seperti yang dilakukan pemilik toko.
        $spreadsheet = IOFactory::load($exported);
        $spreadsheet->getSheetByName('stock')->setCellValue('B2', 15);
        (new XlsxWriter($spreadsheet))->save($exported);

        $this->postImport(new UploadedFile($exported, 'stock.xlsx', null, null, true))
            ->assertOk()
            ->assertJsonPath('sheets.stock.updated', 1);

        $this->assertSame(15, ProductVariant::first()->current_stock);
    }

    // =================================================================
    // Task 6 — image_filename pada sheet products/categories, dicocokkan
    // ke batch berkas 'images[]' yang diunggah bersamaan.
    // =================================================================

    public function test_products_and_categories_sheets_can_reference_uploaded_images_by_filename(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');

        $workbook = $this->workbook([
            'artists' => ['rows' => [
                ['code' => 'RYU', 'name' => 'Ryu Illustration', 'is_active' => 1],
            ]],
            'categories' => ['rows' => [
                ['code' => 'KY', 'name' => 'Keychain', 'is_active' => 1, 'image_filename' => 'kategori-keychain.png'],
            ]],
            'products' => ['rows' => [
                [
                    'artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK',
                    'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard',
                    'sell_price' => 25000, 'image_filename' => 'produk-sakura.jpg',
                ],
            ]],
        ]);

        $categoryImage = UploadedFile::fake()->image('kategori-keychain.png');
        $productImage = UploadedFile::fake()->image('produk-sakura.jpg');

        $response = $this->postImport($workbook, ['images' => [$categoryImage, $productImage]]);

        $response->assertOk()->assertJsonPath('applied', true)->assertJsonPath('errors', []);

        $category = Category::where('code', 'KY')->firstOrFail();
        $product = Product::where('code_prefix', 'RYUKYSAK')->firstOrFail();

        $this->assertNotNull($category->image_path);
        $this->assertNotNull($product->image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($category->image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_a_referenced_image_filename_with_no_matching_upload_is_a_row_level_error(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAsRole('owner');

        $workbook = $this->workbook([
            'categories' => ['rows' => [
                ['code' => 'KY', 'name' => 'Keychain', 'is_active' => 1, 'image_filename' => 'tidak-diunggah.png'],
            ]],
        ]);

        // Sengaja TIDAK mengirim 'images' sama sekali — mensimulasikan
        // pemilik toko yang lupa menyertakan berkas gambar yang
        // direferensikan sheet-nya.
        $response = $this->postImport($workbook);

        $response->assertStatus(422)->assertJsonPath('applied', false);

        $error = collect($response->json('errors'))->firstWhere('column', 'image_filename');
        $this->assertNotNull($error);
        $this->assertSame('categories', $error['sheet']);
        $this->assertDatabaseMissing('categories', ['code' => 'KY']);
    }

    public function test_the_shipped_template_still_imports_cleanly_after_adding_the_image_filename_column(): void
    {
        // Regresi eksplisit untuk Task 6: menambah kolom image_filename ke
        // MasterDataSheets tidak boleh membuat
        // test_the_shipped_template_imports_as_is (di atas) mulai gagal.
        // Kolom baru dibiarkan kosong pada baris contoh (lihat
        // MasterDataSheets::exampleRow()), yang berarti "tidak ada gambar
        // diikutsertakan" — bukan error.
        $this->test_the_shipped_template_imports_as_is();
    }
}
