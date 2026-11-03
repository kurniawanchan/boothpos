<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 090 — impor Excel untuk bahan, stok bahan per outlet, resep, grup/pilihan modifier, bahan pilihan, dan tautan ke
 * item. Satu berkas harus cukup untuk mengisi semuanya di outlet WJM3, dan semua aturan tulis tetap milik service.
 */
class MasterDataImportRecipeModifierTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private array $tempFiles = [];

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
            if ($rows !== []) {
                $ws->fromArray(array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? null, $headings), $rows), null, 'A2');
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp090').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'impor.xlsx', null, null, true);
    }

    private function post090(array $sheets)
    {
        return $this->post('/api/v1/imports/master-data', ['file' => $this->workbook($sheets)], ['Accept' => 'application/json']);
    }

    /** @return array{0: ProductVariant, 1: Outlet} */
    private function setUpShop(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $outlet = Outlet::factory()->create(['code' => 'WJM3', 'name' => 'Warkop WJM 3']);
        $variant = $this->makeVariant();

        return [$variant, $outlet];
    }

    private function fullSheets(ProductVariant $variant): array
    {
        $code = $variant->product->code_prefix;

        return [
            'materials' => [
                ['code' => 'KOPI', 'name' => 'Biji kopi', 'unit' => 'gr', 'is_active' => 1, 'kind' => 'ingredient'],
                ['code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml', 'is_active' => 1],
                ['code' => 'TELUR', 'name' => 'Telur', 'unit' => 'butir', 'is_active' => 1],
            ],
            'material_stock' => [
                ['material_code' => 'KOPI', 'outlet_code' => 'WJM3', 'current_stock' => 1000],
                ['material_code' => 'TELUR', 'outlet_code' => 'WJM3', 'current_stock' => 30, 'reason' => 'stok awal'],
            ],
            'recipes' => [
                ['recipe_name' => 'Kopi susu', 'product_code' => $code, 'portions' => 1, 'material_code' => 'KOPI', 'quantity' => 18, 'shrinkage_pct' => 10, 'note' => 'resep dasar'],
                ['recipe_name' => 'Kopi susu', 'product_code' => $code, 'material_code' => 'SUSU', 'quantity' => 120],
                ['recipe_name' => 'Topping', 'product_code' => $code, 'portions' => 2, 'material_code' => 'TELUR', 'quantity' => 1],
            ],
            'modifier_groups' => [
                ['name' => 'Tambahan', 'selection_type' => 'multiple', 'is_required' => 0, 'min_select' => 0, 'max_select' => 'unlimited'],
                ['name' => 'Ukuran', 'selection_type' => 'single', 'is_required' => 1],
            ],
            'modifier_options' => [
                ['group_name' => 'Tambahan', 'option_name' => 'Telur', 'price_delta' => 3000, 'min_qty' => 1, 'max_qty' => 3],
                ['group_name' => 'Tambahan', 'option_name' => 'Telur Dadar', 'price_delta' => 4000, 'max_qty' => 'unlimited'],
                ['group_name' => 'Ukuran', 'option_name' => 'Kecil', 'price_delta' => 0],
                ['group_name' => 'Ukuran', 'option_name' => 'Besar', 'price_delta' => 5000],
            ],
            'modifier_option_materials' => [
                // SATU bahan (TELUR) dipakai di DUA pilihan — inti permintaan.
                ['group_name' => 'Tambahan', 'option_name' => 'Telur', 'material_code' => 'TELUR', 'quantity' => 1],
                ['group_name' => 'Tambahan', 'option_name' => 'Telur Dadar', 'material_code' => 'TELUR', 'quantity' => 2],
                ['group_name' => 'Ukuran', 'option_name' => 'Besar', 'material_code' => 'KOPI', 'quantity' => 6],
            ],
            'modifier_links' => [
                ['group_name' => 'Tambahan', 'product_code' => $code],
                ['group_name' => 'Ukuran', 'product_code' => $code],
            ],
        ];
    }

    public function test_one_workbook_fills_materials_stock_recipes_modifiers_and_links(): void
    {
        [$variant, $outlet] = $this->setUpShop();

        $this->post090($this->fullSheets($variant))
            ->assertOk()->assertJsonPath('applied', true)->assertJsonPath('errors', []);

        $kopi = Material::where('code', 'KOPI')->firstOrFail();
        $this->assertSame('1000.0000', number_format((float) MaterialOutletStock::where('material_id', $kopi->id)->where('outlet_id', $outlet->id)->value('current_stock'), 4, '.', ''));

        $this->assertSame(2, Recipe::where('product_id', $variant->product_id)->count());
        $recipe = Recipe::where('name', 'Kopi susu')->firstOrFail();
        $this->assertSame(2, $recipe->lines()->count());
        $this->assertEquals(10, (float) $recipe->lines()->first()->shrinkage_pct);
        $this->assertSame([$variant->id], $recipe->variants()->pluck('product_variants.id')->all(), 'variant_skus kosong = semua varian produk');
        $this->assertEquals(2, (float) Recipe::where('name', 'Topping')->first()->yield);

        $tambahan = ModifierGroup::where('name', 'Tambahan')->firstOrFail();
        $this->assertSame('multiple', $tambahan->selection_type);
        $this->assertNull($tambahan->max_select);
        $telur = $tambahan->options()->where('name', 'Telur')->firstOrFail();
        $this->assertSame([1, 3], [$telur->min_qty, $telur->max_qty]);
        $this->assertNull($tambahan->options()->where('name', 'Telur Dadar')->value('max_qty'));

        $telurId = Material::where('code', 'TELUR')->value('id');
        $this->assertSame(2, \App\Models\ModifierOptionMaterial::where('material_id', $telurId)->count(), 'satu bahan di banyak pilihan');
        $this->assertSame([$variant->product_id], $tambahan->products()->pluck('products.id')->all());
        $this->assertSame(1, ModifierGroup::where('name', 'Ukuran')->first()->min_select);
    }

    public function test_reimporting_the_same_workbook_is_idempotent_and_dry_run_writes_nothing(): void
    {
        [$variant] = $this->setUpShop();
        $sheets = $this->fullSheets($variant);

        $this->post('/api/v1/imports/master-data', ['file' => $this->workbook($sheets), 'dry_run' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('applied', false)->assertJsonPath('errors', []);
        $this->assertSame(0, Recipe::count());
        $this->assertSame(0, ModifierGroup::count());

        $this->post090($sheets)->assertOk()->assertJsonPath('applied', true);
        $this->post090($sheets)->assertOk()->assertJsonPath('applied', true)->assertJsonPath('errors', []);

        $this->assertSame(2, Recipe::count());
        $this->assertSame(2, ModifierGroup::count());
        $this->assertSame(4, ModifierOption::count());
        $this->assertSame(1, MaterialOutletStock::where('material_id', Material::where('code', 'KOPI')->value('id'))->count());
        $this->assertSame(2, Recipe::where('name', 'Kopi susu')->first()->lines()->count());
    }

    public function test_editing_a_recipe_in_the_sheet_updates_it_in_place(): void
    {
        [$variant] = $this->setUpShop();
        $sheets = $this->fullSheets($variant);
        $this->post090($sheets)->assertOk();
        $id = Recipe::where('name', 'Kopi susu')->value('id');

        $sheets['recipes'][0]['quantity'] = 20;
        $this->post090($sheets)->assertOk()->assertJsonPath('applied', true);

        $this->assertSame($id, Recipe::where('name', 'Kopi susu')->value('id'));
        $this->assertEquals(20, (float) Recipe::find($id)->lines()->first()->quantity);
    }

    public function test_options_missing_from_a_listed_group_are_deactivated_and_materials_replaced(): void
    {
        [$variant] = $this->setUpShop();
        $sheets = $this->fullSheets($variant);
        $this->post090($sheets)->assertOk();

        unset($sheets['modifier_options'][1], $sheets['modifier_option_materials'][1]); // Telur Dadar dihapus dari daftar
        $sheets['modifier_option_materials'][0]['quantity'] = 5;
        $this->post090(array_map('array_values', $sheets))->assertOk()->assertJsonPath('applied', true);

        $group = ModifierGroup::where('name', 'Tambahan')->first();
        $this->assertFalse((bool) $group->options()->where('name', 'Telur Dadar')->value('is_active'));
        $this->assertEquals(5, (float) $group->options()->where('name', 'Telur')->first()->materials()->first()->quantity);
    }

    public function test_stock_sheet_is_the_final_quantity_not_a_delta(): void
    {
        [$variant] = $this->setUpShop();
        $sheets = $this->fullSheets($variant);
        $this->post090($sheets)->assertOk();

        $sheets['material_stock'][0]['current_stock'] = 400;
        $this->post090($sheets)->assertOk();

        $kopi = Material::where('code', 'KOPI')->first();
        $this->assertEquals(400, (float) MaterialOutletStock::where('material_id', $kopi->id)->value('current_stock'));
        $this->assertDatabaseHas('material_stock_movements', ['material_id' => $kopi->id, 'type' => 'adjustment', 'qty_change' => '-600.0000']);
    }

    public function test_link_rows_can_unlink_and_never_touch_other_links(): void
    {
        [$variant] = $this->setUpShop();
        $other = $this->makeVariant();
        $sheets = $this->fullSheets($variant);
        $sheets['modifier_links'][] = ['group_name' => 'Tambahan', 'product_code' => $other->product->code_prefix];
        $this->post090($sheets)->assertOk();
        $group = ModifierGroup::where('name', 'Tambahan')->first();
        $this->assertCount(2, $group->products);

        $this->post090(['modifier_links' => [['group_name' => 'Tambahan', 'product_code' => $other->product->code_prefix, 'is_linked' => '0']]])
            ->assertOk()->assertJsonPath('applied', true)->assertJsonPath('sheets.modifier_links.rows', 1);

        $this->assertSame([$variant->product_id], $group->products()->pluck('products.id')->all());
    }

    public function test_row_level_errors_name_the_sheet_row_and_column_and_nothing_is_written(): void
    {
        [$variant] = $this->setUpShop();
        $sheets = $this->fullSheets($variant);
        $sheets['recipes'][0]['material_code'] = 'TIDAKADA';
        $sheets['recipes'][2]['product_code'] = 'XXX-XXX-XXX';
        $sheets['modifier_options'][0]['min_qty'] = 5;       // > max_qty 3
        $sheets['modifier_links'][0]['category_code'] = 'KAT'; // product_code DAN category_code
        $sheets['material_stock'][1]['outlet_code'] = 'NOPE';

        $res = $this->post090($sheets)->assertStatus(422)->assertJsonPath('applied', false);
        $errors = collect($res->json('errors'));

        $this->assertTrue($errors->contains(fn ($e) => $e['sheet'] === 'recipes' && $e['row'] === 2 && $e['column'] === 'material_code'));
        $this->assertTrue($errors->contains(fn ($e) => $e['sheet'] === 'recipes' && $e['row'] === 4 && $e['column'] === 'product_code'));
        $this->assertTrue($errors->contains(fn ($e) => $e['sheet'] === 'modifier_options' && $e['row'] === 2 && $e['column'] === 'max_qty'));
        $this->assertTrue($errors->contains(fn ($e) => $e['sheet'] === 'modifier_links' && $e['row'] === 2));
        $this->assertTrue($errors->contains(fn ($e) => $e['sheet'] === 'material_stock' && $e['row'] === 3 && $e['column'] === 'outlet_code'));
        $this->assertSame(0, Recipe::count());
        $this->assertSame(0, ModifierGroup::count());
    }

    public function test_conflicting_recipe_header_duplicate_material_and_missing_portions_are_rejected(): void
    {
        [$variant] = $this->setUpShop();
        $code = $variant->product->code_prefix;
        $sheets = $this->fullSheets($variant);
        $sheets['recipes'] = [
            ['recipe_name' => 'A', 'product_code' => $code, 'portions' => 1, 'material_code' => 'KOPI', 'quantity' => 1],
            ['recipe_name' => 'A', 'product_code' => $code, 'portions' => 2, 'material_code' => 'SUSU', 'quantity' => 1],
            ['recipe_name' => 'A', 'product_code' => $code, 'material_code' => 'KOPI', 'quantity' => 1],
            ['recipe_name' => 'B', 'product_code' => $code, 'material_code' => 'KOPI', 'quantity' => 1],
        ];

        $errors = collect($this->post090($sheets)->assertStatus(422)->json('errors'));

        $this->assertTrue($errors->contains(fn ($e) => $e['column'] === 'portions' && $e['row'] === 3), 'portions bertentangan');
        $this->assertTrue($errors->contains(fn ($e) => $e['column'] === 'material_code' && $e['row'] === 4), 'bahan kembar di satu resep');
        $this->assertTrue($errors->contains(fn ($e) => $e['column'] === 'portions' && $e['row'] === 5), 'portions wajib');
    }

    public function test_export_round_trips_back_into_an_import(): void
    {
        [$variant] = $this->setUpShop();
        $this->post090($this->fullSheets($variant))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'exp090').'.xlsx';
        $this->tempFiles[] = $path;
        $sheets = [];
        foreach ([MasterDataSheets::MATERIAL_STOCK, MasterDataSheets::RECIPES, MasterDataSheets::MODIFIER_GROUPS, MasterDataSheets::MODIFIER_OPTIONS, MasterDataSheets::MODIFIER_OPTION_MATERIALS, MasterDataSheets::MODIFIER_LINKS] as $entity) {
            $res = $this->get("/api/v1/exports/{$entity}")->assertOk();
            file_put_contents($path, $res->streamedContent());
            $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName($entity);
            $rows = $ws->toArray();
            $this->assertSame(MasterDataSheets::headings($entity), $rows[0]);
            $this->assertGreaterThan(1, count($rows), "Ekspor {$entity} kosong.");
            $sheets[$entity] = array_map(fn ($r) => array_combine($rows[0], $r), array_slice($rows, 1));
        }

        $this->post090($sheets)->assertOk()->assertJsonPath('applied', true)->assertJsonPath('errors', []);
        $this->assertSame(2, Recipe::count());
    }

    /** Berkas yang diserahkan ke pemilik: contohnya harus lolos dry run dan impor penuh pada produk WJM3 yang cocok. */
    public function test_the_shipped_wjm3_workbook_imports_cleanly(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $outlet = Outlet::factory()->create(['code' => 'WJM3', 'name' => 'Warkop WJM 3']);
        $product = \App\Models\Product::factory()->create(['home_outlet_id' => $outlet->id, 'code_prefix' => 'WJM-SC-AME', 'name' => 'Americano']);
        $product->variants()->create(['sku' => 'WJM-SC-AME-001', 'variant_name' => 'Standard', 'sell_price' => 20000, 'cost_price' => 0, 'current_stock' => 0]);

        $path = base_path('docs/import-templates/import-bahan-resep-modifier-wjm3.xlsx');
        $this->assertFileExists($path);
        $file = fn () => new UploadedFile($path, 'import-bahan-resep-modifier-wjm3.xlsx', null, null, true);

        $this->post('/api/v1/imports/master-data', ['file' => $file(), 'dry_run' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('errors', [])->assertJsonPath('applied', false)
            ->assertJsonPath('ignored_sheets', ['petunjuk', 'ref_produk', 'ref_kategori']);
        $this->assertSame(0, Material::count());

        $this->post('/api/v1/imports/master-data', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(4, Material::count());
        $this->assertSame(1, Recipe::count());
        $this->assertSame(2, Recipe::first()->lines()->count());
        $this->assertSame(2, ModifierGroup::count());
        $this->assertSame(3, ModifierOption::count());
        $this->assertSame(2, ModifierGroup::where('name', 'Tambahan')->first()->options()->count());
        $this->assertSame([$product->id], ModifierGroup::where('name', 'Susu')->first()->products()->pluck('products.id')->all());
    }

    /** Temuan tinjauan keamanan 2026-11-03: sheet baru memakai izin layar interaktifnya, bukan gerbang kasar "menu master data". */
    public function test_new_sheets_need_the_permission_of_their_own_screen_for_import_and_export(): void
    {
        [$variant] = $this->setUpShop();
        // Hanya menu `materials`: lolos gerbang kasar impor/ekspor, tetapi tak boleh resep, modifier, maupun stok bahan.
        $role = \App\Models\Role::factory()->create(['menu_keys' => ['materials']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->post090([MasterDataSheets::RECIPES => []])->assertForbidden();
        $this->post090([MasterDataSheets::MODIFIER_GROUPS => []])->assertForbidden();
        $this->post090([MasterDataSheets::MATERIAL_STOCK => []])->assertForbidden();
        foreach (['recipes', 'modifier_groups', 'modifier_links', 'material_stock'] as $entity) {
            $this->get("/api/v1/exports/{$entity}")->assertForbidden();
        }
        $this->assertSame(0, Recipe::count());
    }
}
