<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 5 (T075) — sheet `stock` per outlet: jumlah AKHIR per outlet, outlet wajib bila beberapa.
 */
class StockImportOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private ProductVariant $variant;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id, 'code_prefix' => 'IMP-X']);
        $this->variant = $p->variants()->create(['sku' => 'IMP-X-001', 'variant_name' => 'R', 'sell_price' => 1000, 'cost_price' => 500, 'current_stock' => 0]);
        $this->stockAt($this->variant, $this->a, 10);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            is_file($f) && unlink($f);
        }
        parent::tearDown();
    }

    private function import(array $rows)
    {
        $ss = new Spreadsheet;
        $ss->removeSheetByIndex(0);
        $ws = $ss->createSheet();
        $ws->setTitle('stock');
        $h = MasterDataSheets::headings('stock');
        $ws->fromArray($h, null, 'A1');
        $ws->fromArray(array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? null, $h), $rows), null, 'A2', true);
        $path = tempnam(sys_get_temp_dir(), 'stk').'.xlsx';
        (new XlsxWriter($ss))->save($path);
        $this->files[] = $path;

        return $this->post('/api/v1/imports/master-data', ['file' => new UploadedFile($path, 'stok.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    private function bal($o): int
    {
        return (int) \App\Models\VariantOutletStock::where(['variant_id' => $this->variant->id, 'outlet_id' => $o->id])->value('current_stock');
    }

    public function test_absolute_balances_are_applied_per_outlet(): void
    {
        $this->import([
            ['sku' => 'IMP-X-001', 'current_stock' => 4, 'outlet_code' => $this->a->code, 'reason' => 'opname'],
            ['sku' => 'IMP-X-001', 'current_stock' => 9, 'outlet_code' => $this->b->code, 'reason' => 'opname'],
        ])->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(4, $this->bal($this->a));
        $this->assertSame(9, $this->bal($this->b));
        $this->assertSame(13, $this->variant->fresh()->current_stock);
    }

    public function test_the_outlet_is_required_with_several_outlets_and_an_unknown_code_is_a_row_error_with_nothing_written(): void
    {
        $r = $this->import([['sku' => 'IMP-X-001', 'current_stock' => 4]])->assertStatus(422);
        $this->assertSame('outlet_code', $r->json('errors.0.column'));

        $r = $this->import([
            ['sku' => 'IMP-X-001', 'current_stock' => 1, 'outlet_code' => $this->b->code],
            ['sku' => 'IMP-X-001', 'current_stock' => 2, 'outlet_code' => 'NOPE'],
        ])->assertStatus(422);
        $this->assertSame('outlet_code', $r->json('errors.0.column'));
        $this->assertSame(0, $this->bal($this->b), 'all-or-nothing');
    }

    public function test_the_export_carries_the_outlet_and_round_trips(): void
    {
        $this->stockAt($this->variant, $this->b, 3);

        $rows = \App\Services\MasterDataExportService::class;
        $data = app($rows)->rows('stock');

        $this->assertEqualsCanonicalizing(
            [[$this->a->code, 10], [$this->b->code, 3]],
            collect($data)->map(fn ($r) => [$r['outlet_code'], (int) $r['current_stock']])->all(),
        );
    }
}
