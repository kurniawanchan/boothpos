<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/** 043 (T022) — kolom baru sheet products: dibaca saat impor, ditulis saat ekspor, `status` hanya ekspor. */
class ProductImportNewColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        Outlet::factory()->create(['code' => 'RYU']);
        Category::factory()->create(['code' => 'KY']);
    }

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            is_file($path) && unlink($path);
        }
        parent::tearDown();
    }

    private function upload(array $row): \Illuminate\Testing\TestResponse
    {
        $headings = MasterDataSheets::headings(MasterDataSheets::PRODUCTS);
        $data = array_merge(MasterDataSheets::exampleRow(MasterDataSheets::PRODUCTS), $row);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('products');
        $ws->fromArray($headings, null, 'A1');
        $ws->fromArray([array_map(fn ($h) => $data[$h] ?? null, $headings)], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'boothpos-import').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return $this->post('/api/v1/imports/master-data', ['file' => new UploadedFile($path, 'impor.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    public function test_the_new_columns_are_appended_after_the_existing_ones(): void
    {
        $headers = MasterDataSheets::headings(MasterDataSheets::PRODUCTS);

        $this->assertSame('variant_image_filename', $headers[array_search('short_name', $headers) - 1]);
        $this->assertSame(['short_name', 'tax_category', 'prep_minutes', 'is_retail', 'status'], array_slice($headers, -5));
    }

    public function test_import_writes_the_facts_and_ignores_the_derived_status(): void
    {
        $this->upload(['product_name' => 'Kopi Susu', 'short_name' => 'KopSu', 'tax_category' => 'restaurant_food_beverage', 'prep_minutes' => 4, 'is_retail' => 1, 'status' => 'discontinued'])
            ->assertSuccessful();

        $p = Product::where('name', 'Kopi Susu')->firstOrFail();
        $this->assertSame('KopSu', $p->short_name);
        $this->assertSame(4, $p->prep_minutes);
        $this->assertTrue($p->is_retail);
        $this->assertSame('active', $p->status);   // `status` di berkas tidak dibaca
    }

    public function test_bad_values_are_reported_per_row_and_nothing_is_imported(): void
    {
        $res = $this->upload(['product_name' => 'Teh', 'short_name' => str_repeat('x', 25), 'prep_minutes' => -3]);

        $res->assertStatus(422);
        $this->assertSame(0, Product::count());
    }

    public function test_export_carries_the_new_columns_with_the_derived_status(): void
    {
        $this->upload(['product_name' => 'Roti', 'short_name' => 'Roti', 'prep_minutes' => 2, 'is_retail' => 1])->assertSuccessful();

        $rows = (new \App\Services\MasterDataExportService())->rows(MasterDataSheets::PRODUCTS);

        $this->assertSame(['Roti', 2, 1, 'active'], [$rows[0]['short_name'], $rows[0]['prep_minutes'], $rows[0]['is_retail'], $rows[0]['status']]);
    }
}
