<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Import vendor/material/vendor_prices/bom — ditambahkan ke workbook
 * gabungan yang sama dengan artists/categories/products/stock, pasca-MVP
 * 2026-09-01 (lihat catatan bertanggal di CLAUDE.md/README.md/PRD).
 * Struktur test ini sengaja meniru MasterDataImportTest (helper workbook()
 * yang sama) alih-alih membangun mekanisme berkas terpisah.
 */
class MasterDataImportVendorMaterialTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_happy_path_creates_vendors_materials_prices_and_bom(): void
    {
        $this->actingAsRole('owner');
        $variant = ProductVariant::factory()->create(['sku' => 'EXISTINGSKU1']);

        $file = $this->workbook([
            'vendors' => ['rows' => [
                ['code' => 'VNAKR', 'name' => 'Toko Akrilik Jaya', 'is_active' => 1],
            ]],
            'materials' => ['rows' => [
                ['code' => 'AC3', 'name' => 'Acrylic sheet 3mm', 'unit' => 'lembar', 'is_active' => 1],
            ]],
            'vendor_prices' => ['rows' => [
                ['vendor_code' => 'VNAKR', 'material_code' => 'AC3', 'price' => 15000, 'is_preferred' => 1],
            ]],
            'bom' => ['rows' => [
                ['sku' => $variant->sku, 'material_code' => 'AC3', 'qty_needed' => 2],
            ]],
        ]);

        $response = $this->postImport($file);

        $response->assertOk()->assertJsonPath('applied', true)->assertJsonPath('errors', []);

        $this->assertDatabaseHas('vendors', ['code' => 'VNAKR']);
        $this->assertDatabaseHas('materials', ['code' => 'AC3']);
        $this->assertDatabaseHas('vendor_material_prices', ['price' => 15000.00, 'is_preferred' => true]);
        $this->assertDatabaseHas('product_variant_bom_lines', ['product_variant_id' => $variant->id, 'qty_needed' => 2.0000]);
    }

    public function test_a_row_referencing_an_unknown_vendor_code_is_a_row_level_error(): void
    {
        $this->actingAsRole('owner');
        Material::factory()->create(['code' => 'AC3']);

        $response = $this->postImport($this->workbook([
            'vendor_prices' => ['rows' => [
                ['vendor_code' => 'GHOST', 'material_code' => 'AC3', 'price' => 1000],
            ]],
        ]));

        $response->assertStatus(422);
        $response->assertJsonFragment(['sheet' => 'vendor_prices', 'row' => 2, 'column' => 'vendor_code']);
        $this->assertDatabaseMissing('vendor_material_prices', ['price' => 1000]);
    }

    public function test_bom_row_may_reference_a_sku_created_by_the_products_sheet_in_the_same_file(): void
    {
        $this->actingAsRole('owner');
        Material::factory()->create(['code' => 'AC3']);

        $file = $this->workbook([
            'artists' => ['rows' => [['code' => 'RYU', 'name' => 'Ryu', 'is_active' => 1]]],
            'categories' => ['rows' => [['code' => 'KY', 'name' => 'Keychain', 'is_active' => 1]]],
            'products' => ['rows' => [[
                'artist_code' => 'RYU', 'category_code' => 'KY', 'product_segment' => 'SAK',
                'product_name' => 'Keychain Sakura', 'variant_name' => 'Standard',
                'sell_price' => 25000, 'initial_stock' => 10,
            ]]],
            'bom' => ['rows' => [
                ['sku' => 'RYUKYSAK0001', 'material_code' => 'AC3', 'qty_needed' => 1],
            ]],
        ]);

        $response = $this->postImport($file);

        $response->assertOk()->assertJsonPath('applied', true);
        $this->assertDatabaseHas('product_variant_bom_lines', ['material_id' => Material::where('code', 'AC3')->value('id')]);
    }

    public function test_reimporting_updates_price_instead_of_duplicating(): void
    {
        $this->actingAsRole('owner');
        Vendor::factory()->create(['code' => 'VNAKR']);
        Material::factory()->create(['code' => 'AC3']);

        $this->postImport($this->workbook([
            'vendor_prices' => ['rows' => [
                ['vendor_code' => 'VNAKR', 'material_code' => 'AC3', 'price' => 15000],
            ]],
        ]))->assertOk();

        $this->postImport($this->workbook([
            'vendor_prices' => ['rows' => [
                ['vendor_code' => 'VNAKR', 'material_code' => 'AC3', 'price' => 17000],
            ]],
        ]))->assertOk();

        $this->assertDatabaseCount('vendor_material_prices', 1);
        $this->assertDatabaseHas('vendor_material_prices', ['price' => 17000.00]);
    }

    public function test_extended_template_still_imports_as_is(): void
    {
        $this->actingAsRole('owner');

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, $this->get('/api/v1/imports/master-data/template')->streamedContent());

        $this->postImport(new UploadedFile($path, 'template-impor-master-data.xlsx', null, null, true))
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseHas('vendors', ['code' => 'VNAKR']);
        $this->assertDatabaseHas('materials', ['code' => 'AC3']);
        $this->assertDatabaseHas('vendor_material_prices', ['is_preferred' => true]);
        $this->assertDatabaseHas('product_variant_bom_lines', ['qty_needed' => 1.0000]);
    }

    public function test_dry_run_does_not_write_vendor_material_data(): void
    {
        $this->actingAsRole('owner');

        $response = $this->postImport($this->workbook([
            'vendors' => ['rows' => [['code' => 'VNAKR', 'name' => 'Toko Akrilik', 'is_active' => 1]]],
        ]), ['dry_run' => 1]);

        $response->assertOk()->assertJsonPath('applied', false)->assertJsonPath('dry_run', true);
        $this->assertDatabaseMissing('vendors', ['code' => 'VNAKR']);
    }
}
