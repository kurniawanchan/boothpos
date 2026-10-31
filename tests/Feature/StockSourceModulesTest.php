<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\ModifierMaterialFixtures;
use Tests\TestCase;

/**
 * 087 US6 (T059, SC-009) — satu uji per modul FR-030: produk bersumber `modifier` tidak punya stok varian di
 * koreksi, transfer, stok rendah, saldo, laporan stok per outlet, dan impor/ekspor master data.
 */
class StockSourceModulesTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase;

    private User $owner;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        // Stok rendah: ambang di atas saldo, supaya keduanya calon "stok rendah".
        $this->indomie->update(['low_stock_alert' => 1000]);
        $this->nasiGoreng->update(['low_stock_alert' => 1000]);
        $this->indomie->product->forceFill(['stock_source' => Product::STOCK_MODIFIER])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_a_variant_stock_adjustment_of_a_modifier_product_is_refused(): void
    {
        $this->postJson('/api/v1/stock/adjustments', ['outlet_id' => $this->outletA->id, 'reason' => 'opname', 'items' => [['variant_id' => $this->indomie->id, 'qty_change' => 5]]])
            ->assertStatus(409)->assertJsonPath('code', 'stock_source_modifier');

        $this->postJson('/api/v1/stock/adjustments', ['outlet_id' => $this->outletA->id, 'reason' => 'opname', 'items' => [['variant_id' => $this->nasiGoreng->id, 'qty_change' => 5]]])
            ->assertSuccessful();
    }

    public function test_a_transfer_line_for_a_modifier_product_is_refused(): void
    {
        $this->postJson('/api/v1/stock/transfers', [
            'from_outlet_id' => $this->outletA->id, 'to_outlet_id' => $this->outletB->id,
            'lines' => [['kind' => 'variant', 'item_id' => $this->indomie->id, 'qty' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
    }

    public function test_low_stock_and_balances_leave_out_modifier_products(): void
    {
        $low = collect($this->getJson('/api/v1/stock/low')->assertOk()->json('data'))->pluck('variant_id')->all();
        $this->assertNotContains($this->indomie->id, $low);
        $this->assertContains($this->nasiGoreng->id, $low);

        $balances = collect($this->getJson('/api/v1/stock/balances?variant_id[]='.$this->indomie->id.'&variant_id[]='.$this->nasiGoreng->id)->assertOk()->json('data'))->pluck('variant_id')->all();
        $this->assertNotContains($this->indomie->id, $balances);
        $this->assertContains($this->nasiGoreng->id, $balances);
    }

    public function test_the_stock_by_outlet_report_leaves_out_modifier_products(): void
    {
        $skus = array_column($this->getJson('/api/v1/reports/stock-by-outlet?outlet_id='.$this->outletA->id)->assertOk()->json('variants'), 'sku');
        $this->assertNotContains($this->indomie->sku, $skus);
        $this->assertContains($this->nasiGoreng->sku, $skus);

        // Ringkasan per outlet: hanya varian bersumber `variant` yang dihitung (Nasi Goreng 500 di outlet A).
        $a = collect($this->getJson('/api/v1/reports/stock-by-outlet')->assertOk()->json('data'))->firstWhere('outlet_id', $this->outletA->id);
        $this->assertSame(1, $a['variant_count']);
    }

    public function test_master_data_export_has_the_column_and_import_reads_it_all_or_nothing(): void
    {
        $this->assertContains('stock_source', MasterDataSheets::headings(MasterDataSheets::PRODUCTS));

        $this->import([MasterDataSheets::PRODUCTS => [['sku' => $this->nasiGoreng->sku, 'stock_source' => 'modifier']]])->assertOk();
        $this->assertSame('modifier', $this->nasiGoreng->product->fresh()->stock_source);

        // Kosong = tidak berubah.
        $this->import([MasterDataSheets::PRODUCTS => [['sku' => $this->nasiGoreng->sku, 'sell_price' => 21000]]])->assertOk();
        $this->assertSame('modifier', $this->nasiGoreng->product->fresh()->stock_source);

        // Nilai salah → galat baris, tidak ada yang diterapkan.
        $this->import([MasterDataSheets::PRODUCTS => [
            ['sku' => $this->indomie->sku, 'stock_source' => 'variant'],
            ['sku' => $this->nasiGoreng->sku, 'stock_source' => 'gudang'],
        ]])->assertStatus(422)->assertJsonFragment(['sheet' => 'products', 'column' => 'stock_source']);
        $this->assertSame('modifier', $this->indomie->product->fresh()->stock_source);
    }

    public function test_the_stock_sheet_refuses_a_row_for_a_modifier_product(): void
    {
        $this->import([MasterDataSheets::STOCK => [['sku' => $this->indomie->sku, 'current_stock' => 10, 'outlet_code' => $this->outletA->code]]])
            ->assertStatus(422)->assertJsonFragment(['sheet' => 'stock', 'column' => 'sku']);
    }

    private function import(array $sheets)
    {
        return $this->post('/api/v1/imports/master-data', ['file' => $this->workbook($sheets)], ['Accept' => 'application/json']);
    }

    private function workbook(array $sheets): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $headings = MasterDataSheets::headings(MasterDataSheets::canonicalName($title));
            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle($title);
            $worksheet->fromArray($headings, null, 'A1');
            $worksheet->fromArray(array_map(fn (array $row) => array_map(fn (string $h) => $row[$h] ?? null, $headings), $rows), null, 'A2');
        }
        $path = tempnam(sys_get_temp_dir(), 'boothpos-import').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
