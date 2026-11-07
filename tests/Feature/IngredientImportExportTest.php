<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\ModifierGroup;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US5 (FR-029; research D11) — lembar `recipes` hanya memuat bahan baku. Ekspor melewati baris produk, dan impor yang
 * menimpa resep di tempat MEMPERTAHANKAN baris produk (basis dan blok bermodifier), supaya impor ulang tidak menghapusnya.
 */
class IngredientImportExportTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 100]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $f) {
            is_file($f) && unlink($f);
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
        $path = tempnam(sys_get_temp_dir(), 'imp093').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->temp[] = $path;

        return new UploadedFile($path, 'impor.xlsx', null, null, true);
    }

    private function importRecipes(array $rows)
    {
        return $this->post('/api/v1/imports/master-data', ['file' => $this->workbook(['recipes' => $rows])], ['Accept' => 'application/json']);
    }

    /** Resep dasar: bahan baku `soto` + produk `telur`; blok bermodifier Telur: produk `mie`. */
    private function mixedRecipe(): Recipe
    {
        $recipe = $this->ingredientRecipe($this->nasiGoreng, ['m:soto' => 2, 'telur' => 1], name: 'Dasar');
        $option = $this->opt['telur'];
        $block = $recipe->blocks()->create(['variant_id' => $this->nasiGoreng->id, 'modifier_option_id' => $option->id, 'display_order' => 2]);
        $block->lines()->create(['recipe_id' => $recipe->id, 'variant_id' => $this->ing['mie']->id, 'quantity' => 1, 'shrinkage_pct' => 0, 'display_order' => 1]);

        return $recipe;
    }

    /** @return list<array<string, mixed>> */
    private function exportedRecipeRows(): array
    {
        $res = $this->get('/api/v1/exports/recipes');
        $res->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp093').'.xlsx';
        file_put_contents($path, $res->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? file_get_contents($res->baseResponse->getFile()->getPathname()) : $res->streamedContent());
        $this->temp[] = $path;
        $ws = IOFactory::load($path)->getSheetByName('recipes') ?? IOFactory::load($path)->getSheet(0);
        $raw = $ws->toArray(null, true, true, false);
        $headings = array_shift($raw);

        return array_values(array_filter(array_map(fn ($r) => array_combine($headings, $r), $raw), fn ($r) => array_filter($r, fn ($v) => $v !== null && $v !== '') !== []));
    }

    public function test_the_export_skips_product_lines_and_never_writes_an_empty_material_code(): void
    {
        $this->mixedRecipe();

        $rows = $this->exportedRecipeRows();

        $this->assertCount(1, $rows, 'hanya baris bahan baku (soto) yang diekspor');
        $this->assertSame($this->mat['soto']->code, $rows[0]['material_code']);
        $this->assertSame('Dasar', $rows[0]['recipe_name']);
        $this->assertNotEmpty($rows[0]['portions'], 'baris pertama tetap membawa kepala resep');
    }

    public function test_a_recipe_made_only_of_product_lines_exports_no_rows(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1, 'mie' => 1]);

        $this->assertSame([], $this->exportedRecipeRows());
    }

    public function test_importing_over_a_recipe_keeps_its_product_lines_in_base_and_modifier_blocks(): void
    {
        $recipe = $this->mixedRecipe();
        $code = $this->nasiGoreng->product->code_prefix;
        Material::query()->whereKey($this->mat['soto']->id)->update(['code' => 'SOTO']);

        $this->importRecipes([['recipe_name' => 'Dasar', 'product_code' => $code, 'portions' => 1, 'material_code' => 'SOTO', 'quantity' => 5]])
            ->assertSuccessful();

        $recipe->refresh()->load('blocks.lines');
        $base = $recipe->blocks->firstWhere('modifier_option_id', null);
        $this->assertSame(5.0, (float) $base->lines->firstWhere('material_id', $this->mat['soto']->id)->quantity, 'bahan baku diperbarui dari lembar');
        $this->assertSame([$this->ing['telur']->id], $base->lines->whereNotNull('variant_id')->pluck('variant_id')->all(), 'baris produk di blok dasar tetap');
        $modifier = $recipe->blocks->firstWhere('modifier_option_id', $this->opt['telur']->id);
        $this->assertNotNull($modifier, 'blok bermodifier tetap');
        $this->assertSame([$this->ing['mie']->id], $modifier->lines->whereNotNull('variant_id')->pluck('variant_id')->all(), 'baris produk di blok bermodifier tetap');
        $this->assertSame(1, Recipe::count(), 'diubah di tempat, bukan resep baru');
    }

    public function test_importing_over_a_recipe_with_only_product_lines_in_a_base_block_keeps_them(): void
    {
        $recipe = $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1], name: 'Dasar');
        $code = $this->nasiGoreng->product->code_prefix;
        Material::query()->whereKey($this->mat['soto']->id)->update(['code' => 'SOTO']);

        $this->importRecipes([['recipe_name' => 'Dasar', 'product_code' => $code, 'portions' => 1, 'material_code' => 'SOTO', 'quantity' => 3]])
            ->assertSuccessful();

        $lines = RecipeLine::where('recipe_id', $recipe->id)->get();
        $this->assertCount(2, $lines);
        $this->assertSame(1, $lines->whereNotNull('variant_id')->count(), 'baris produk tidak terhapus diam-diam');
    }

    public function test_exporting_and_re_importing_the_file_leaves_the_recipe_unchanged(): void
    {
        $recipe = $this->mixedRecipe();
        $before = RecipeLine::where('recipe_id', $recipe->id)->orderBy('id')->get(['material_id', 'variant_id', 'quantity'])->toArray();

        $rows = $this->exportedRecipeRows();
        $this->importRecipes($rows)->assertSuccessful();

        $after = RecipeLine::where('recipe_id', $recipe->id)->get(['material_id', 'variant_id', 'quantity'])->sortBy(fn ($l) => [$l->variant_id ?? 0, $l->material_id ?? 0])->values()->toArray();
        $this->assertEqualsCanonicalizing(array_map('json_encode', $before), array_map('json_encode', $after));
        $this->assertSame(1, Recipe::count());
    }

    /** Nilai 0 harus berupa TEKS: PhpSpreadsheet::fromArray memperlakukan angka 0 sebagai null dan tidak menulis selnya. */
    private function importProductRow(array $row)
    {
        $headings = MasterDataSheets::headings(MasterDataSheets::PRODUCTS);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('products');
        $ws->fromArray($headings, null, 'A1');
        $ws->fromArray([array_map(fn ($h) => $row[$h] ?? null, $headings)], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'imp093p').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->temp[] = $path;

        return $this->post('/api/v1/imports/master-data', ['file' => new UploadedFile($path, 'impor.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    public function test_import_cannot_deactivate_or_re_source_a_product_a_recipe_still_uses(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1], name: 'Nasi Telur');
        $sku = $this->ing['telur']->sku;

        foreach ([['product_is_active' => '0'], ['variant_is_active' => '0'], ['stock_source' => 'modifier']] as $change) {
            $res = $this->importProductRow(['sku' => $sku] + $change);
            $res->assertStatus(409)->assertJsonPath('code', 'ingredient_in_use')->assertJsonPath('recipes.0.name', 'Nasi Telur');
        }
        $this->assertTrue((bool) $this->ing['telur']->product->fresh()->is_active);
        $this->assertTrue((bool) $this->ing['telur']->fresh()->is_active);
        $this->assertSame('variant', $this->ing['telur']->product->fresh()->stock_source);
    }

    public function test_import_re_sending_the_same_values_for_a_used_product_still_works(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);

        $this->importProductRow(['sku' => $this->ing['telur']->sku, 'product_is_active' => '1', 'variant_is_active' => '1', 'stock_source' => 'variant', 'description' => 'baru'])
            ->assertSuccessful();
        $this->assertSame('baru', $this->ing['telur']->product->fresh()->description);
    }

    public function test_import_may_deactivate_a_product_no_recipe_uses(): void
    {
        $free = $this->makeIngredient('Gula', 5, ['use_as_ingredient' => false]);

        $this->importProductRow(['sku' => $free->sku, 'product_is_active' => '0'])->assertSuccessful();
        $this->assertFalse((bool) $free->product->fresh()->is_active);
    }
}
