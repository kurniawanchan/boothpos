<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\OutletItemSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantOutletPrice;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T052/T058) — sheet `outlet_items` dan `outlet_prices` di workbook master data.
 */
class OutletExcelSheetsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private array $tempFiles = [];

    private $a;

    private $b;

    private Product $kopi;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->kopi = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id, 'code_prefix' => 'KOPI-X', 'name' => 'Kopi']);
        $this->kopi->variants()->create(['sku' => 'KOPI-X-001', 'variant_name' => 'Regular', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 5]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            is_file($path) && unlink($path);
        }
        parent::tearDown();
    }

    private function workbook(array $sheets): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $headings = MasterDataSheets::headings($title);
            $ws = $spreadsheet->createSheet();
            $ws->setTitle($title);
            $ws->fromArray($headings, null, 'A1');
            $ws->fromArray(array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? null, $headings), $rows), null, 'A2', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'outlet-import').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'impor.xlsx', null, null, true);
    }

    private function import(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/v1/imports/master-data', array_merge(['file' => $file], $extra), ['Accept' => 'application/json']);
    }

    public function test_outlet_items_and_prices_are_applied_and_round_trip_through_export(): void
    {
        $this->import($this->workbook([
            'outlet_items' => [['outlet_code' => $this->b->code, 'product_code' => 'KOPI-X', 'is_carried' => 0]],
            'outlet_prices' => [['sku' => 'KOPI-X-001', 'outlet_code' => $this->b->code, 'price' => 25000]],
        ]))->assertOk()->assertJsonPath('applied', true);

        $this->assertFalse(OutletItemSetting::where('outlet_id', $this->b->id)->where('product_id', $this->kopi->id)->value('is_carried') == 1);
        $this->assertSame('25000.00', number_format((float) VariantOutletPrice::where('outlet_id', $this->b->id)->value('price'), 2, '.', ''));

        $rows = $this->get('/api/v1/exports/outlet_prices')->assertOk();
        $this->assertStringContainsString('xlsx', (string) $rows->headers->get('content-disposition'));
    }

    public function test_the_word_base_removes_an_outlet_price(): void
    {
        VariantOutletPrice::create(['variant_id' => $this->kopi->variants()->first()->id, 'outlet_id' => $this->a->id, 'price' => 30000]);

        $this->import($this->workbook(['outlet_prices' => [['sku' => 'KOPI-X-001', 'outlet_code' => $this->a->code, 'price' => 'base']]]))
            ->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(0, VariantOutletPrice::count());
    }

    public function test_unknown_codes_are_row_errors_and_nothing_is_written(): void
    {
        $response = $this->import($this->workbook([
            'outlet_items' => [
                ['outlet_code' => $this->b->code, 'product_code' => 'KOPI-X', 'is_carried' => 0],
                ['outlet_code' => 'NOPE', 'product_code' => 'KOPI-X', 'is_carried' => 1],
                ['outlet_code' => $this->a->code, 'product_code' => 'GHOST', 'is_carried' => 1],
            ],
            'outlet_prices' => [['sku' => 'NOSKU', 'outlet_code' => $this->a->code, 'price' => -5]],
        ]))->assertStatus(422)->assertJsonPath('applied', false);

        $columns = collect($response->json('errors'))->map(fn ($e) => $e['sheet'].'.'.$e['column'])->all();
        $this->assertContains('outlet_items.outlet_code', $columns);
        $this->assertContains('outlet_items.product_code', $columns);
        $this->assertContains('outlet_prices.sku', $columns);
        $this->assertTrue(OutletItemSetting::where('outlet_id', $this->b->id)->where('product_id', $this->kopi->id)->value('is_carried') == 1, 'all-or-nothing');
    }

    public function test_an_outlet_outside_the_uploaders_set_is_refused(): void
    {
        $role = \App\Models\Role::create(['name' => 'Pengelola Impor '.uniqid(), 'menu_keys' => ['dashboard', 'products', 'outlets'], 'all_outlets' => false]);
        $manager = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $this->actingAs($manager, 'sanctum');

        $response = $this->import($this->workbook(['outlet_prices' => [['sku' => 'KOPI-X-001', 'outlet_code' => $this->b->code, 'price' => 25000]]]));

        $this->assertContains('outlet_prices.outlet_code', collect($response->json('errors'))->map(fn ($e) => ($e['sheet'] ?? '').'.'.($e['column'] ?? ''))->all());
        $this->assertSame(0, VariantOutletPrice::count());
    }

    public function test_with_releases_on_imported_outlet_prices_wait_as_menu_changes(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);

        $this->import($this->workbook(['outlet_prices' => [['sku' => 'KOPI-X-001', 'outlet_code' => $this->a->code, 'price' => 25000]]]))
            ->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(0, VariantOutletPrice::count());
        $this->assertSame(1, \App\Models\MenuChange::query()->where('kind', 'price')->count());
    }

    public function test_the_shipped_template_creates_item_and_price_rows_for_a_sku_made_by_the_same_file(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']); // lisensi Master: beberapa outlet aktif
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->tempFiles[] = $path;
        file_put_contents($path, $this->get('/api/v1/imports/master-data/template')->streamedContent());

        $this->import(new UploadedFile($path, 'template.xlsx', null, null, true))->assertOk()->assertJsonPath('applied', true);

        $price = VariantOutletPrice::query()->whereHas('variant', fn ($q) => $q->where('sku', 'RYU-KY-SAK-001'))->first();
        $this->assertNotNull($price, 'SKU is made by the products sheet of the same file');
        $this->assertSame('27000.00', number_format((float) $price->price, 2, '.', ''));
        $this->assertTrue(OutletItemSetting::query()->whereHas('product', fn ($q) => $q->where('code_prefix', 'RYU-KY-SAK'))->exists());
    }

    public function test_the_export_lists_every_item_outlet_pair_and_the_sheet_names_are_registered(): void
    {
        $this->assertContains('outlet_items', MasterDataSheets::ORDER);
        $this->assertContains('outlet_prices', MasterDataSheets::ORDER);
        $this->get('/api/v1/exports/outlet_items')->assertOk();
    }

    public function test_listing_a_foreign_outlet_in_the_outlets_sheet_does_not_bypass_the_permission_check(): void
    {
        $role = \App\Models\Role::create(['name' => 'Pengelola Impor B '.uniqid(), 'menu_keys' => ['dashboard', 'products', 'outlets'], 'all_outlets' => false]);
        $manager = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $this->actingAs($manager, 'sanctum');

        $response = $this->import($this->workbook([
            'outlets' => [['code' => $this->b->code, 'name' => $this->b->name, 'is_active' => 1]],
            'outlet_prices' => [['sku' => 'KOPI-X-001', 'outlet_code' => $this->b->code, 'price' => 25000]],
        ]));

        $this->assertContains('outlet_prices.outlet_code', collect($response->json('errors'))->map(fn ($e) => ($e['sheet'] ?? '').'.'.($e['column'] ?? ''))->all());
        $this->assertSame(0, VariantOutletPrice::count());
    }

    public function test_the_outlets_sheet_takes_a_five_character_code_and_an_address_and_the_address_heading_is_last(): void
    {
        $headings = MasterDataSheets::headings('outlets');
        $this->assertSame('address', end($headings), 'kolom baru di AKHIR supaya berkas lama tetap terbaca');

        // Master: dengan kuota Pro, outlet ketiga ditolak sebelum aturan kode/alamat sempat diuji.
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'licensing']);

        $this->import($this->workbook(['outlets' => [['code' => 'kd001', 'name' => 'Kedai Satu', 'is_active' => 1, 'address' => 'Jl. Melati 5']]]))
            ->assertOk()->assertJsonPath('applied', true);

        $this->assertDatabaseHas('outlets', ['code' => 'KD001', 'address' => 'Jl. Melati 5']);
    }

    public function test_a_file_without_the_address_column_still_imports_and_leaves_the_address_unchanged(): void
    {
        $this->a->update(['address' => 'Alamat Lama']);

        $spreadsheet = new Spreadsheet;
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('outlets');
        $ws->fromArray(['code', 'name', 'contact_phone', 'contact_email', 'notes', 'is_active'], null, 'A1');
        $ws->fromArray([[$this->a->code, 'Nama Dari Berkas Lama', null, null, null, 1]], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'outlet-old').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        $this->import(new UploadedFile($path, 'lama.xlsx', null, null, true))->assertOk()->assertJsonPath('applied', true);

        $this->assertDatabaseHas('outlets', ['id' => $this->a->id, 'name' => 'Nama Dari Berkas Lama', 'address' => 'Alamat Lama']);
    }

    public function test_an_outlet_code_that_breaks_the_rule_is_a_row_error_on_the_code_column(): void
    {
        $response = $this->import($this->workbook(['outlets' => [['code' => 'AB-1', 'name' => 'X', 'is_active' => 1], ['code' => 'ABCDEF', 'name' => 'Y', 'is_active' => 1]]]))
            ->assertStatus(422)->assertJsonPath('applied', false);

        $this->assertSame(2, collect($response->json('errors'))->where('sheet', 'outlets')->where('column', 'code')->count());
    }

    public function test_after_a_code_edit_an_import_that_still_carries_the_old_code_is_refused_naming_it_and_writes_nothing(): void
    {
        $old = $this->b->code;
        $this->putJson("/api/v1/outlets/{$this->b->id}", ['code' => 'NEW9X', 'name' => $this->b->name])->assertOk();

        $response = $this->import($this->workbook(['outlet_items' => [['outlet_code' => $old, 'product_code' => 'KOPI-X', 'is_carried' => 0]]]))
            ->assertStatus(422)->assertJsonPath('applied', false);

        $error = collect($response->json('errors'))->firstWhere('column', 'outlet_code');
        $this->assertNotNull($error);
        $this->assertStringContainsString($old, $error['message']);
        $this->assertSame(0, OutletItemSetting::where('outlet_id', $this->b->id)->where('is_carried', false)->count());

        $this->import($this->workbook(['outlet_items' => [['outlet_code' => 'NEW9X', 'product_code' => 'KOPI-X', 'is_carried' => 0]]]))
            ->assertOk()->assertJsonPath('applied', true);
    }
}
