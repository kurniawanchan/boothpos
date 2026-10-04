<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Event;
use App\Models\Material;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 034-seller-po-bom (US4) — BOM selesai: harga modal varian mengikuti biaya
 * BOM dan dikunci terhadap edit manual; membuka kembali melepas kunci;
 * transaksi yang sudah tercatat tidak berubah.
 */
class VariantBomCompleteTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $sellerA;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->sellerA = Artist::factory()->create(['name' => 'Seller A']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            is_file($path) && unlink($path);
        }

        parent::tearDown();
    }

    /** Varian dengan BOM contoh: bahan 500 + 300, jasa 1.000 = 1.800. */
    private function variantWithBom(): ProductVariant
    {
        $variant = $this->variantFor($this->sellerA);
        [$chain] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        [$ring] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Keychain Ring', 'price' => 300]]);
        [$assembly] = $this->po($this->sellerA, 'received', [['type' => 'service', 'name' => 'Assembly', 'price' => 1000]], 'Vendor Y');
        $this->addItems($variant, [
            ['purchase_order_item_id' => $chain->id], ['purchase_order_item_id' => $ring->id], ['purchase_order_item_id' => $assembly->id],
        ])->assertCreated();

        return $variant;
    }

    private function complete(ProductVariant $variant)
    {
        return $this->postJson("/api/v1/variants/{$variant->id}/bom/complete");
    }

    private function reopen(ProductVariant $variant)
    {
        return $this->postJson("/api/v1/variants/{$variant->id}/bom/reopen");
    }

    private function cost(ProductVariant $variant): string
    {
        return number_format((float) $variant->fresh()->cost_price, 2, '.', '');
    }

    // ===================================================================
    // Completing and reopening
    // ===================================================================

    public function test_completing_sets_the_flag_syncs_the_cost_price_and_audits_both(): void
    {
        $variant = $this->variantWithBom();
        $variant->update(['cost_price' => 123]);

        $this->complete($variant)->assertOk()
            ->assertJsonPath('summary.bom_complete', true)
            ->assertJsonPath('summary.cost_price', '1800.00')
            ->assertJsonPath('summary.bom_cost', '1800.00');

        $fresh = $variant->fresh();
        $this->assertTrue($fresh->bom_complete);
        $this->assertNotNull($fresh->bom_completed_at);
        $this->assertSame($this->owner->id, $fresh->bom_completed_by);
        $this->assertSame('1800.00', $this->cost($variant));

        $this->assertSame(1, ActivityLog::where('action', 'bom_completed')->where('entity_id', $variant->id)->count());
        $sync = ActivityLog::where('action', 'cost_price_synced')->where('entity_id', $variant->id)->firstOrFail();
        $this->assertSame('123.00', $sync->old_values['cost_price']);
        $this->assertSame('1800.00', $sync->new_values['cost_price']);
    }

    public function test_completing_is_refused_for_an_empty_bom_a_legacy_row_or_an_invalid_row(): void
    {
        $empty = $this->variantFor($this->sellerA);
        $this->complete($empty)->assertStatus(409)->assertJsonPath('code', 'empty');

        $withLegacy = $this->variantWithBom();
        $legacy = ProductVariantBomLine::factory()->create(['product_variant_id' => $withLegacy->id, 'material_id' => Material::factory()->create(['name' => 'Tali Lama'])->id]);
        $this->complete($withLegacy)->assertStatus(409)->assertJsonPath('code', 'has_legacy')
            ->assertJsonPath('rows.0.id', $legacy->id)->assertJsonPath('rows.0.item_name', 'Tali Lama');

        $invalid = $this->variantWithBom();
        $invalid->bomLines()->first()->update(['qty_needed' => 0]);
        $this->complete($invalid)->assertStatus(409)->assertJsonPath('code', 'invalid_row');

        $other = $this->variantWithBom();
        $other->product->update(['artist_id' => Artist::factory()->create()->id]);
        $this->complete($other)->assertStatus(409)->assertJsonPath('code', 'invalid_row');

        foreach ([$empty, $withLegacy, $invalid, $other] as $v) {
            $this->assertFalse($v->fresh()->bom_complete);
        }
        $this->assertSame(0, ActivityLog::where('action', 'bom_completed')->count());
    }

    public function test_a_cancelled_source_order_does_not_block_completion(): void
    {
        $variant = $this->variantWithBom();
        $variant->bomLines()->first()->purchaseOrderItem->purchaseOrder->update(['status' => 'cancelled']);

        $this->complete($variant)->assertOk()->assertJsonPath('summary.cost_price', '1800.00');
    }

    public function test_changes_to_a_complete_bom_resync_the_cost_price(): void
    {
        $variant = $this->variantWithBom();
        $this->complete($variant)->assertOk();
        $rows = $this->getJson("/api/v1/variants/{$variant->id}/bom")->json('data');

        // qty 1 -> 3 untuk Ball Chain (500): 1.800 + 1.000 = 2.800
        $this->putJson('/api/v1/bom/'.$rows[0]['id'], ['qty_needed' => 3])->assertOk()->assertJsonPath('summary.cost_price', '2800.00');
        $this->assertSame('2800.00', $this->cost($variant));

        // tambah baris baru (700) -> 3.500
        [$extra] = $this->po($this->sellerA, 'paid', [['type' => 'material', 'name' => 'Charm', 'price' => 700]]);
        $this->addItems($variant, [['purchase_order_item_id' => $extra->id]])->assertCreated();
        $this->assertSame('3500.00', $this->cost($variant));

        // hapus baris Ring (300) -> 3.200, masih selesai
        $this->deleteJson('/api/v1/bom/'.$rows[1]['id'])->assertOk()->assertJsonPath('summary.bom_complete', true);
        $this->assertSame('3200.00', $this->cost($variant));
        $this->assertGreaterThanOrEqual(3, ActivityLog::where('action', 'cost_price_synced')->count());
    }

    public function test_removing_the_last_row_reopens_the_bom_and_keeps_the_last_cost_price(): void
    {
        $variant = $this->variantFor($this->sellerA);
        [$only] = $this->po($this->sellerA, 'ordered', [['type' => 'material', 'name' => 'Ball Chain', 'price' => 500]]);
        $rowId = $this->addItems($variant, [['purchase_order_item_id' => $only->id]])->json('data.0.id');
        $this->complete($variant)->assertOk();
        $this->assertSame('500.00', $this->cost($variant));

        $this->deleteJson("/api/v1/bom/{$rowId}")->assertOk()
            ->assertJsonPath('summary.reopened', true)->assertJsonPath('summary.bom_complete', false)->assertJsonPath('data', []);

        $this->assertFalse($variant->fresh()->bom_complete);
        $this->assertSame('500.00', $this->cost($variant), 'cost price keeps its last value');
        $this->assertSame(1, ActivityLog::where('action', 'bom_reopened')->count());
    }

    public function test_reopening_releases_the_lock_and_keeps_the_value(): void
    {
        $variant = $this->variantWithBom();
        $this->complete($variant)->assertOk();

        $this->reopen($variant)->assertOk()->assertJsonPath('summary.bom_complete', false)->assertJsonPath('summary.cost_price', '1800.00');

        $fresh = $variant->fresh();
        $this->assertFalse($fresh->bom_complete);
        $this->assertNull($fresh->bom_completed_at);
        $this->assertSame('1800.00', $this->cost($variant));
        $this->putJson("/api/v1/variants/{$variant->id}", ['cost_price' => 2000])->assertOk();
        $this->assertSame('2000.00', $this->cost($variant));
        $this->assertSame(1, ActivityLog::where('action', 'bom_reopened')->count());
    }

    public function test_completing_or_reopening_twice_is_harmless(): void
    {
        $variant = $this->variantWithBom();
        $this->reopen($variant)->assertOk();                       // belum selesai: no-op
        $this->complete($variant)->assertOk();
        $this->complete($variant)->assertOk();                     // sudah selesai: no-op
        $this->assertSame(1, ActivityLog::where('action', 'bom_completed')->count());
    }

    // ===================================================================
    // Lock on the cost price
    // ===================================================================

    public function test_a_complete_bom_locks_the_cost_price_against_manual_edits(): void
    {
        $variant = $this->variantWithBom();
        $this->complete($variant)->assertOk();

        $this->putJson("/api/v1/variants/{$variant->id}", ['cost_price' => 999])
            ->assertStatus(409)->assertJsonPath('code', 'cost_price_locked_by_bom');
        $this->assertSame('1800.00', $this->cost($variant));

        // Nilai yang SAMA diterima (SPA mengirim seluruh varian), field lain tetap bisa diubah.
        $this->putJson("/api/v1/variants/{$variant->id}", ['cost_price' => 1800, 'sell_price' => 25000])->assertOk();
        $this->assertSame('25000.00', number_format((float) $variant->fresh()->sell_price, 2, '.', ''));
        $this->putJson("/api/v1/variants/{$variant->id}", ['sell_price' => 26000])->assertOk();
    }

    public function test_product_payloads_expose_bom_state_for_users_who_can_manage_products(): void
    {
        $variant = $this->variantWithBom();
        $this->complete($variant)->assertOk();
        $plain = $this->variantFor($this->sellerA);

        $variants = collect($this->getJson("/api/v1/products/{$variant->product_id}")->assertOk()->json('variants'));
        $this->assertTrue($variants[0]['bom_complete']);
        $this->assertTrue($variants[0]['has_bom']);
        $this->assertSame('1800.00', $variants[0]['bom_cost']);

        $other = collect($this->getJson("/api/v1/products/{$plain->product_id}")->assertOk()->json('variants'))->first();
        $this->assertFalse($other['bom_complete']);
        $this->assertFalse($other['has_bom']);
        $this->assertNull($other['bom_cost']);
    }

    // ===================================================================
    // Authorization
    // ===================================================================

    public function test_completing_and_reopening_need_both_menus(): void
    {
        $variant = $this->variantWithBom();

        $productsOnly = User::factory()->create(['role_id' => Role::factory()->create(['name' => 'Staf Produk', 'menu_keys' => ['products']])->id]);
        $this->actingAs($productsOnly, 'sanctum');
        $this->complete($variant)->assertForbidden();
        $this->reopen($variant)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->complete($variant)->assertForbidden();
        $this->assertFalse($variant->fresh()->bom_complete);
    }

    // ===================================================================
    // History: recorded transactions never change
    // ===================================================================

    public function test_recorded_sales_keep_their_cost_and_new_sales_use_the_synced_cost(): void
    {
        $variant = $this->variantWithBom();
        $variant->update(['cost_price' => 100, 'current_stock' => 100]);

        $cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $sell = fn () => app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $cashier);

        $before = $sell();
        $profitBefore = $this->getJson("/api/v1/reports/profit?event_id={$event->id}")->assertOk()->json();

        $this->complete($variant)->assertOk();

        $this->assertSame('100.00', number_format((float) OrderItem::where('order_id', $before->id)->value('cost_price'), 2, '.', ''));
        $profitAfter = $this->getJson("/api/v1/reports/profit?event_id={$event->id}")->assertOk()->json();
        $this->assertSame($profitBefore['cost_of_goods'], $profitAfter['cost_of_goods']);
        $this->assertSame($profitBefore['gross_profit'], $profitAfter['gross_profit']);

        $after = $sell();
        $this->assertSame('1800.00', number_format((float) OrderItem::where('order_id', $after->id)->value('cost_price'), 2, '.', ''));
    }

    // ===================================================================
    // Excel import guards
    // ===================================================================

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

        return new UploadedFile($path, 'impor.xlsx', null, null, true);
    }

    private function import(array $sheets)
    {
        return $this->post('/api/v1/imports/master-data', ['file' => $this->workbook($sheets)], ['Accept' => 'application/json']);
    }

    public function test_the_products_sheet_cannot_change_the_cost_price_of_a_complete_variant(): void
    {
        $variant = $this->variantWithBom();
        $this->complete($variant)->assertOk();

        $this->import([MasterDataSheets::PRODUCTS => [['sku' => $variant->sku, 'cost_price' => 999]]])
            ->assertStatus(422)->assertJsonPath('applied', false)
            ->assertJsonFragment(['sheet' => 'products', 'row' => 2, 'column' => 'cost_price']);
        $this->assertSame('1800.00', $this->cost($variant));

        // Nilai yang sama dan sel kosong (= tidak diubah) tetap boleh.
        $this->import([MasterDataSheets::PRODUCTS => [['sku' => $variant->sku, 'cost_price' => 1800, 'sell_price' => 30000]]])->assertOk();
        $this->import([MasterDataSheets::PRODUCTS => [['sku' => $variant->sku, 'sell_price' => 31000]]])->assertOk();
    }

    public function test_the_products_sheet_still_edits_the_cost_price_of_a_variant_whose_bom_is_not_complete(): void
    {
        $variant = $this->variantWithBom();

        $this->import([MasterDataSheets::PRODUCTS => [['sku' => $variant->sku, 'cost_price' => 999]]])->assertOk();
        $this->assertSame('999.00', $this->cost($variant));
    }

    public function test_the_bom_sheet_cannot_touch_a_complete_variant_and_never_overwrites_a_po_row(): void
    {
        $variant = $this->variantWithBom();
        $material = Material::where('name', 'Ball Chain')->firstOrFail();
        $poRow = $variant->bomLines()->where('material_id', $material->id)->firstOrFail();

        // BOM belum selesai: baris Excel membuat baris LEGACY baru, TIDAK menimpa baris PO yang bahannya sama.
        $this->import([MasterDataSheets::BOM => [['sku' => $variant->sku, 'material_code' => $material->code, 'qty_needed' => 9]]])->assertOk();
        $this->assertEquals(1, $poRow->fresh()->qty_needed);
        $this->assertSame(1, $variant->bomLines()->whereNull('purchase_order_item_id')->count());
        $legacy = $variant->bomLines()->whereNull('purchase_order_item_id')->first();
        $this->assertEquals(9, $legacy->qty_needed);

        // Selesaikan (hapus baris legacy dulu), lalu sheet BOM harus ditolak.
        $legacy->delete();
        $this->complete($variant)->assertOk();
        $this->import([MasterDataSheets::BOM => [['sku' => $variant->sku, 'material_code' => $material->code, 'qty_needed' => 2]]])
            ->assertStatus(422)->assertJsonPath('applied', false)
            ->assertJsonFragment(['sheet' => 'bom', 'row' => 2, 'column' => 'sku']);
        $this->assertEquals(1, $poRow->fresh()->qty_needed);
    }

    public function test_the_legacy_bom_endpoint_is_refused_for_a_complete_variant(): void
    {
        $variant = $this->variantWithBom();
        $this->complete($variant)->assertOk();

        $this->postJson("/api/v1/variants/{$variant->id}/bom", ['material_id' => Material::factory()->create()->id, 'qty_needed' => 1])
            ->assertStatus(409);
        $this->assertSame(0, $variant->bomLines()->whereNull('purchase_order_item_id')->count());
    }
}
