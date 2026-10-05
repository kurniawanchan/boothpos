<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\User;
use App\Models\VendorMaterialPrice;
use App\Services\VariantBomService;
use App\Support\MasterDataSheets;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SakanaFridgeDemoSeeder;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 034-seller-po-bom (US6) — baris BOM lama (bahan + jumlah, tanpa baris PO)
 * dibawa maju: tetap terlihat, dihitung seperti sebelumnya dari harga acuan
 * vendor, ditandai legacy, menghalangi "BOM selesai", dan bisa diganti satu
 * per satu dengan baris PO.
 */
class VariantBomLegacyTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    private ProductVariant $variant;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();
        $this->variant = $this->variantFor($this->seller);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            is_file($path) && unlink($path);
        }

        parent::tearDown();
    }

    /** Baris legacy dengan harga acuan vendor 8.000 (preferred) dan jumlah 2. */
    private function legacyRow(string $name = 'Tali Lama', float $price = 8000, float $qty = 2): ProductVariantBomLine
    {
        $material = Material::factory()->create(['name' => $name]);
        VendorMaterialPrice::factory()->create(['material_id' => $material->id, 'price' => $price, 'is_preferred' => true]);

        return ProductVariantBomLine::factory()->create(['product_variant_id' => $this->variant->id, 'material_id' => $material->id, 'qty_needed' => $qty]);
    }

    private function bom(): array
    {
        return $this->getJson("/api/v1/variants/{$this->variant->id}/bom")->assertOk()->json();
    }

    // ===================================================================

    public function test_legacy_rows_stay_visible_flagged_and_costed_from_the_vendor_price_list(): void
    {
        $legacy = $this->legacyRow();

        $payload = $this->bom();
        $row = $payload['data'][0];

        $this->assertSame($legacy->id, $row['id']);
        $this->assertTrue($row['is_legacy']);
        $this->assertNull($row['purchase_order_item_id']);
        $this->assertNull($row['po_number']);
        $this->assertSame('Tali Lama', $row['item_name']);
        $this->assertSame('8000.00', $row['unit_cost']);
        $this->assertSame('16000.00', $row['item_cost']);
        $this->assertNull($row['newer_price']);
        $this->assertFalse($row['source_cancelled']);
        $this->assertSame('16000.00', $payload['summary']['material_cost']);
        $this->assertSame('16000.00', $payload['summary']['bom_cost']);
        $this->assertTrue($payload['summary']['has_legacy']);
    }

    public function test_legacy_and_po_rows_mix_in_one_total_and_the_legacy_cost_still_follows_the_price_list_live(): void
    {
        $this->legacyRow();
        [$assembly] = $this->po($this->seller, 'ordered', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]]);
        $this->addItems($this->variant, [['purchase_order_item_id' => $assembly->id]])->assertCreated();

        $summary = $this->bom()['summary'];
        $this->assertSame('16000.00', $summary['material_cost']);
        $this->assertSame('1000.00', $summary['service_cost']);
        $this->assertSame('17000.00', $summary['bom_cost']);

        VendorMaterialPrice::query()->update(['price' => 9000]);   // harga acuan berubah -> baris legacy ikut (perilaku lama)
        $this->assertSame('19000.00', $this->bom()['summary']['bom_cost']);
    }

    public function test_a_legacy_row_blocks_completion_and_the_message_names_it(): void
    {
        $this->legacyRow('Tali Lama');
        [$item] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        $this->addItems($this->variant, [['purchase_order_item_id' => $item->id]])->assertCreated();

        $res = $this->postJson("/api/v1/variants/{$this->variant->id}/bom/complete")->assertStatus(409)->assertJsonPath('code', 'has_legacy');

        $this->assertStringContainsString('Tali Lama', $res->json('message'));
    }

    public function test_replacing_a_legacy_row_with_a_po_line_converts_it_keeps_the_quantity_and_unblocks_completion(): void
    {
        $legacy = $this->legacyRow('Tali Lama', 8000, 2);
        [$line] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Tali Baru', 'price' => 500]], 'Vendor X', null, 'PO-LEG');

        $res = $this->postJson("/api/v1/bom/{$legacy->id}/replace-source", ['purchase_order_item_id' => $line->id])->assertOk()
            ->assertJsonPath('data.0.is_legacy', false)->assertJsonPath('data.0.po_number', 'PO-LEG')
            ->assertJsonPath('data.0.unit_cost', '500.00')->assertJsonPath('data.0.qty_needed', '2.0000')
            ->assertJsonPath('summary.has_legacy', false)->assertJsonPath('summary.bom_cost', '1000.00');
        $this->assertSame($line->id, $res->json('data.0.purchase_order_item_id'));

        $log = ActivityLog::where('action', 'bom_source_replaced')->firstOrFail();
        $this->assertNull($log->old_values['purchase_order_item_id']);
        $this->assertSame('PO-LEG', $log->new_values['po_number']);

        $this->postJson("/api/v1/variants/{$this->variant->id}/bom/complete")->assertOk()->assertJsonPath('summary.cost_price', '1000.00');
    }

    public function test_a_legacy_material_row_can_become_a_service_row(): void
    {
        $legacy = $this->legacyRow();
        [$service] = $this->po($this->seller, 'ordered', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]]);

        $this->postJson("/api/v1/bom/{$legacy->id}/replace-source", ['purchase_order_item_id' => $service->id])->assertOk()
            ->assertJsonPath('data.0.line_type', 'service')->assertJsonPath('data.0.material_id', null)
            ->assertJsonPath('summary.service_cost', '2000.00')->assertJsonPath('summary.material_cost', '0.00');
    }

    // ===================================================================
    // The legacy endpoint and the Excel sheet keep working (for a variant that is not complete)
    // ===================================================================

    public function test_the_legacy_endpoint_creates_an_audited_legacy_row_and_keeps_one_legacy_row_per_material(): void
    {
        $material = Material::factory()->create();

        $this->postJson("/api/v1/variants/{$this->variant->id}/bom", ['material_id' => $material->id, 'qty_needed' => 3, 'notes' => 'lama']) // 036: bilangan bulat
            ->assertCreated()->assertJsonPath('is_legacy', true)->assertJsonPath('qty_needed', '3.0000');

        $log = ActivityLog::where('action', 'bom_item_added')->firstOrFail();
        $this->assertSame($this->owner->id, $log->user_id);

        $this->postJson("/api/v1/variants/{$this->variant->id}/bom", ['material_id' => $material->id, 'qty_needed' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('material_id');

        // Baris bersumber PO dengan bahan yang SAMA tidak menghalangi baris legacy.
        $other = Material::factory()->create(['name' => 'Ball Chain']);
        [$po] = $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        $this->addItems($this->variant, [['purchase_order_item_id' => $po->id]])->assertCreated();
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom", ['material_id' => $po->material_id, 'qty_needed' => 1])->assertCreated();
    }

    private function importBom(array $rows)
    {
        $headings = MasterDataSheets::headings(MasterDataSheets::BOM);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(MasterDataSheets::BOM);
        $sheet->fromArray($headings, null, 'A1');
        $sheet->fromArray(array_map(fn (array $r) => array_map(fn (string $h) => $r[$h] ?? null, $headings), $rows), null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'boothpos-import').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return $this->post('/api/v1/imports/master-data', ['file' => new UploadedFile($path, 'impor.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    public function test_the_excel_bom_sheet_creates_then_updates_a_single_legacy_row(): void
    {
        $material = Material::factory()->create();

        $this->importBom([['sku' => $this->variant->sku, 'material_code' => $material->code, 'qty_needed' => 3]])->assertOk();
        $this->importBom([['sku' => $this->variant->sku, 'material_code' => $material->code, 'qty_needed' => 5]])->assertOk();

        $rows = $this->variant->bomLines()->get();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->isLegacy());
        $this->assertEquals(5, $rows[0]->qty_needed);
    }

    // ===================================================================
    // Seeded demo data
    // ===================================================================

    public function test_the_demo_seeder_still_seeds_its_bom_lines_and_they_read_as_legacy_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SakanaFridgeDemoSeeder::class);

        ModeGate::runAs('demo', function () {
            $this->assertGreaterThanOrEqual(1, ProductVariantBomLine::count());
            $this->assertSame(0, ProductVariantBomLine::whereNotNull('purchase_order_item_id')->count());

            $variant = ProductVariant::whereHas('bomLines')->firstOrFail();
            $payload = app(VariantBomService::class)->payload($variant);

            $this->assertNotEmpty($payload['data']);
            $this->assertTrue($payload['summary']['has_legacy']);
            foreach ($payload['data'] as $row) {
                $this->assertTrue($row['is_legacy']);
            }
        });
    }
}
