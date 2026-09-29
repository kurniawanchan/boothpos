<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 022-preorder-invoice-crud-overhaul (US7, FR-017/FR-018) — export/import
 * via the new one-row-per-order layout, which REPLACES the row-per-item
 * layout feature 007 originally shipped (resolved Question 3 = full
 * replace).
 */
class PreorderExportImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        'customer_name', 'event_name', 'fulfillment', 'pickup_day',
        'products', 'quantities', 'unit_prices',
        'shipping_cost', 'courier_name', 'expected_date', 'discount', 'notes',
        'dispatch_status', 'invoice_sent_at', 'shipping_at',
    ];

    private function makeVariant(string $sku, float $sellPrice = 100000): \App\Models\ProductVariant
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);

        return $product->variants()->create(['sku' => $sku, 'sell_price' => $sellPrice, 'cost_price' => 50000, 'current_stock' => 0]);
    }

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_cashier_is_forbidden_from_export_and_import(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/preorders/export')->assertStatus(403);
        $this->postJson('/api/v1/preorders/import')->assertStatus(403);
    }

    public function test_template_uses_the_new_one_row_per_order_column_set(): void
    {
        $this->actingAsOwner();

        $response = $this->get('/api/v1/preorders/import/template');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_export_respects_active_filters(): void
    {
        $owner = $this->actingAsOwner();
        $variant = $this->makeVariant('EXPKYEXP0001');
        $customer = Customer::factory()->create();

        $preorderA = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ])->json();
        $this->patchJson("/api/v1/preorders/{$preorderA['id']}/status", ['status' => 'dp_paid']);

        $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ]);

        $response = $this->getJson('/api/v1/preorders/export?status=dp_paid');
        $response->assertOk();
    }

    private function importRow(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Pelanggan Baru', 'event_name' => '', 'fulfillment' => 'pickup',
            'pickup_day' => '', 'products' => '', 'quantities' => '', 'unit_prices' => '',
            'shipping_cost' => '', 'courier_name' => '', 'expected_date' => '', 'discount' => '', 'notes' => '',
            'dispatch_status' => '', 'invoice_sent_at' => '', 'shipping_at' => '',
        ], $overrides);
    }

    private function doImport(array $rowValues, bool $dryRun = false)
    {
        $path = $this->writeXlsx([self::HEADINGS, array_values($this->importRow($rowValues))]);
        $file = new UploadedFile($path, 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        return $this->postJson('/api/v1/preorders/import', ['file' => $file, 'dry_run' => $dryRun ? '1' : '0']);
    }

    public function test_import_creates_one_preorder_with_multiple_items_matched_by_position(): void
    {
        $this->actingAsOwner();
        $variantA = $this->makeVariant('IMPA0001', 100000);
        $variantB = $this->makeVariant('IMPB0001', 50000);

        $response = $this->doImport([
            'customer_name' => 'Pelanggan Baru',
            'fulfillment' => 'pickup',
            'products' => "{$variantA->sku},{$variantB->sku}",
            'quantities' => '2,3',
            'unit_prices' => '100000,50000',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, $response->json('created_count'));
        $this->assertSame(1, $response->json('created_customer_count'));

        $preorder = Preorder::with('items')->findOrFail($response->json('preorder_ids.0'));
        $this->assertSame('ordered', $preorder->status);
        $this->assertCount(2, $preorder->items);
        $this->assertEquals('2', $preorder->items->firstWhere('sku_snapshot', $variantA->sku)->qty);
        $this->assertEquals('3', $preorder->items->firstWhere('sku_snapshot', $variantB->sku)->qty);
        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Baru']);
    }

    public function test_import_matches_event_by_name_and_resolves_day_n_pickup_day(): void
    {
        $this->actingAsOwner();
        $variant = $this->makeVariant('IMPC0001');
        $event = Event::factory()->create(['name' => 'Comifuro 24', 'start_date' => '2026-11-01', 'end_date' => '2026-11-03']);

        $response = $this->doImport([
            'customer_name' => 'Pelanggan Event',
            'event_name' => 'Comifuro 24',
            'fulfillment' => 'pickup',
            'pickup_day' => 'Day 2',
            'products' => $variant->sku, 'quantities' => '1', 'unit_prices' => '100000',
        ]);

        $response->assertStatus(201);
        $preorder = Preorder::findOrFail($response->json('preorder_ids.0'));
        $this->assertEquals($event->id, $preorder->event_id);
        $this->assertEquals('2026-11-02', $preorder->pickup_day->toDateString());
    }

    public function test_import_rejects_a_row_whose_products_and_quantities_counts_differ(): void
    {
        $this->actingAsOwner();
        $variant = $this->makeVariant('IMPD0001');

        $response = $this->doImport([
            'customer_name' => 'Pelanggan Salah',
            'fulfillment' => 'pickup',
            'products' => "{$variant->sku},{$variant->sku}",
            'quantities' => '1',
            'unit_prices' => '100000,100000',
        ]);

        $response->assertStatus(409);
        $this->assertNotEmpty($response->json('row_errors'));
        $this->assertDatabaseCount('preorders', 0);
    }

    public function test_import_with_one_bad_row_creates_nothing(): void
    {
        $this->actingAsOwner();
        $variant = $this->makeVariant('IMPKYIMP0002');

        $path = $this->writeXlsx([
            self::HEADINGS,
            array_values($this->importRow(['customer_name' => 'Pelanggan Baik', 'products' => $variant->sku, 'quantities' => '1', 'unit_prices' => '100000'])),
            array_values($this->importRow(['customer_name' => 'Pelanggan Buruk', 'products' => 'SKU-TIDAK-ADA', 'quantities' => '1', 'unit_prices' => '100000'])),
        ]);
        $file = new UploadedFile($path, 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(409);
        $this->assertNotEmpty($response->json('row_errors'));
        $this->assertDatabaseCount('preorders', 0);
        $this->assertDatabaseMissing('customers', ['name' => 'Pelanggan Baik']);
    }

    public function test_export_then_reimport_round_trips_without_modification(): void
    {
        $owner = $this->actingAsOwner();
        $variant = $this->makeVariant('RTKY0001', 75000);
        $event = Event::factory()->create(['name' => 'Round Trip Event', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02']);
        $customer = Customer::factory()->create(['name' => 'Pelanggan Round Trip']);

        $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'event_id' => $event->id, 'fulfillment' => 'pickup',
            'pickup_day' => '2026-12-02',
            'items' => [['variant_id' => $variant->id, 'qty' => 2]],
        ])->assertCreated();

        $exportRows = app(\App\Services\PreorderExportImportService::class)->export([]);
        $this->assertCount(1, $exportRows);
        $row = $exportRows[0];
        $this->assertSame('Pelanggan Round Trip', $row['customer_name']);
        $this->assertSame('Round Trip Event', $row['event_name']);
        $this->assertSame('pickup', $row['fulfillment']);
        $this->assertSame('Day 2', $row['pickup_day']);
        $this->assertSame($variant->sku, $row['products']);
        $this->assertSame('2', $row['quantities']);

        \App\Models\PreorderItem::query()->delete();
        Preorder::query()->delete();

        $path = $this->writeXlsx([self::HEADINGS, array_values(array_intersect_key($row, array_flip(self::HEADINGS)))]);
        $file = new UploadedFile($path, 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(201);
        $this->assertSame(1, $response->json('created_count'));
    }

    /**
     * Guards a real bug found via a real user report: template()'s FIRST
     * returned row used to be `array_combine(HEADINGS, HEADINGS)` — meant
     * only to feed GenericArrayExport::headings() its keys, but
     * GenericArrayExport::array() writes EVERY row (including that one) as
     * literal spreadsheet data too, so the downloaded template's first
     * data row had every cell equal to its own column name (e.g.
     * event_name = "event_name"), and re-importing it unmodified always
     * failed. Mirrors MasterDataImportService's own
     * test_the_shipped_template_imports_as_is guard.
     */
    public function test_the_shipped_template_imports_as_is(): void
    {
        $this->actingAsOwner();

        $templateRows = app(\App\Services\PreorderExportImportService::class)->template();
        $this->assertCount(1, $templateRows, 'template() must not leak a header-as-values row');
        $exampleRow = $templateRows[0];

        // Buat variant untuk SKU contoh apa adanya (bukan hardcode SKU
        // demo seeder tertentu di test — test ini hanya menjamin template()
        // sendiri konsisten dan importable, bukan bergantung pada seeder).
        foreach (explode(',', $exampleRow['products']) as $sku) {
            $this->makeVariant(trim($sku));
        }

        $path = $this->writeXlsx([self::HEADINGS, array_values($exampleRow)]);
        $file = new UploadedFile($path, 'template-preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(201);
        $this->assertSame(1, $response->json('created_count'));
        $this->assertEmpty($response->json('row_errors'));
    }

    // --- Penanda invoice-terkirim / pengiriman-berjalan (dispatch_status) -------

    private function exportService(): \App\Services\PreorderExportImportService
    {
        return app(\App\Services\PreorderExportImportService::class);
    }

    /** Buat pre-order via API lalu set dispatch_status-nya; kembalikan responsnya. */
    private function makeDispatchPreorder(string $sku, string $fulfillment, string $dispatch): array
    {
        $variant = \App\Models\ProductVariant::where('sku', $sku)->first() ?? $this->makeVariant($sku);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => Customer::factory()->create()->id, 'fulfillment' => $fulfillment,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        if ($dispatch !== 'pending') {
            return $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => $dispatch])
                ->assertOk()->json();
        }

        return $preorder;
    }

    public function test_export_carries_dispatch_columns_and_read_only_created_updated(): void
    {
        $this->actingAsOwner();
        $this->makeDispatchPreorder('DSPX0001', 'courier', 'shipping');

        $row = $this->exportService()->export([])[0];

        $this->assertSame('shipping', $row['dispatch_status']);
        // Melompat langsung pending → shipping tidak mengarang tanggal invoice.
        $this->assertNull($row['invoice_sent_at']);
        $this->assertNotNull($row['shipping_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $row['shipping_at']);
        $this->assertNotNull($row['created_at']);
        $this->assertNotNull($row['updated_at']);
        // created_at/updated_at hanya untuk dibaca — bukan bagian dari kolom impor.
        $this->assertSame(self::HEADINGS, array_slice(array_keys($row), 0, count(self::HEADINGS)));
        $this->assertSame(['created_at', 'updated_at'], array_slice(array_keys($row), count(self::HEADINGS)));
    }

    public function test_template_has_dispatch_columns_but_not_the_read_only_ones(): void
    {
        $this->actingAsOwner();

        $keys = array_keys($this->exportService()->template()[0]);

        $this->assertSame(self::HEADINGS, $keys);
        $this->assertNotContains('created_at', $keys);
        $this->assertNotContains('updated_at', $keys);
    }

    public function test_export_filters_by_dispatch_status_as_scalar_or_array_and_arrays_for_status_and_fulfillment(): void
    {
        $this->actingAsOwner();
        $this->makeDispatchPreorder('DSPF0001', 'courier', 'pending');
        $this->makeDispatchPreorder('DSPF0001', 'courier', 'invoice_sent');
        $this->makeDispatchPreorder('DSPF0001', 'courier', 'shipping');
        $this->makeDispatchPreorder('DSPF0001', 'pickup', 'invoice_sent');

        $count = fn (array $f) => count($this->exportService()->export($f));

        $this->assertSame(1, $count(['dispatch_status' => 'shipping']));
        $this->assertSame(2, $count(['dispatch_status' => ['invoice_sent']]));
        $this->assertSame(3, $count(['dispatch_status' => ['invoice_sent', 'shipping']]));
        // Filter list sekarang berbentuk array (status[]=…, fulfillment[]=…) — export
        // harus menerimanya juga, bukan hanya nilai tunggal.
        $this->assertSame(4, $count(['status' => ['ordered', 'dp_paid'], 'fulfillment' => ['courier', 'pickup']]));
        $this->assertSame(3, $count(['fulfillment' => ['courier']]));
        $this->assertSame(1, $count(['fulfillment' => ['pickup'], 'dispatch_status' => ['invoice_sent']]));

        // Dan lewat HTTP persis seperti tombol "Export .xlsx" mengirimnya.
        $this->get('/api/v1/preorders/export?dispatch_status[]=shipping&status[]=ordered&fulfillment[]=courier')->assertOk();
    }

    public function test_import_sets_dispatch_status_and_keeps_given_dates(): void
    {
        $this->actingAsOwner();
        $this->makeVariant('DSPI0001');

        $response = $this->doImport([
            'products' => 'DSPI0001', 'quantities' => '1', 'unit_prices' => '10000', 'fulfillment' => 'mail order',
            'dispatch_status' => 'shipping', 'invoice_sent_at' => '2026-09-01T10:00:00+07:00', 'shipping_at' => '2026-09-02T09:30:00+07:00',
        ]);

        $response->assertStatus(201);
        $preorder = Preorder::firstOrFail();
        $this->assertSame('shipping', $preorder->dispatch_status);
        $this->assertSame('2026-09-01 03:00:00', $preorder->invoice_sent_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-02 02:30:00', $preorder->shipping_at->format('Y-m-d H:i:s'));
    }

    public function test_import_blank_dispatch_status_means_pending_with_no_dates(): void
    {
        $this->actingAsOwner();
        $this->makeVariant('DSPI0002');

        $this->doImport(['products' => 'DSPI0002', 'quantities' => '1', 'unit_prices' => '10000'])->assertStatus(201);

        $preorder = Preorder::firstOrFail();
        $this->assertSame('pending', $preorder->dispatch_status);
        $this->assertNull($preorder->invoice_sent_at);
        $this->assertNull($preorder->shipping_at);
    }

    public function test_import_fills_a_missing_date_with_now_for_the_active_status_only(): void
    {
        $this->actingAsOwner();
        $this->makeVariant('DSPI0003');

        $this->doImport(['products' => 'DSPI0003', 'quantities' => '1', 'unit_prices' => '10000', 'dispatch_status' => 'invoice_sent'])->assertStatus(201);
        $sent = Preorder::firstOrFail();
        $this->assertNotNull($sent->invoice_sent_at);
        $this->assertNull($sent->shipping_at);

        \App\Models\PreorderItem::query()->delete(); // FK preorder_items → preorders: RESTRICT
        Preorder::query()->delete();
        $this->doImport([
            'products' => 'DSPI0003', 'quantities' => '1', 'unit_prices' => '10000', 'fulfillment' => 'mail order', 'dispatch_status' => 'shipping',
        ])->assertStatus(201);
        $shipping = Preorder::firstOrFail();
        $this->assertNotNull($shipping->shipping_at);
        // Sama seperti API: melompat langsung ke shipping tidak mengarang tanggal invoice.
        $this->assertNull($shipping->invoice_sent_at);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDispatchRows')]
    public function test_import_rejects_inconsistent_dispatch_rows_without_creating_anything(array $overrides): void
    {
        $this->actingAsOwner();
        $this->makeVariant('DSPI0004');

        $response = $this->doImport(array_merge(
            ['products' => 'DSPI0004', 'quantities' => '1', 'unit_prices' => '10000'],
            $overrides,
        ));

        $response->assertStatus(409); // konvensi: impor yang ditolak = 409, sama seperti test 'one bad row'
        $this->assertNotEmpty($response->json('row_errors'));
        $this->assertDatabaseCount('preorders', 0);
    }

    public static function invalidDispatchRows(): array
    {
        return [
            'unknown status' => [['dispatch_status' => 'delivered']],
            'shipping on pickup' => [['fulfillment' => 'pickup', 'dispatch_status' => 'shipping']],
            'date on pending' => [['dispatch_status' => 'pending', 'invoice_sent_at' => '2026-09-01T10:00:00+07:00']],
            'date with blank status' => [['dispatch_status' => '', 'shipping_at' => '2026-09-01T10:00:00+07:00']],
            'shipping date on invoice_sent' => [['dispatch_status' => 'invoice_sent', 'shipping_at' => '2026-09-01T10:00:00+07:00']],
            'unparseable date' => [['dispatch_status' => 'invoice_sent', 'invoice_sent_at' => 'kemarin sore']],
        ];
    }

    public function test_status_is_matched_case_insensitively_and_ignores_surrounding_spaces(): void
    {
        $this->actingAsOwner();
        $this->makeVariant('DSPI0005');

        $this->doImport(['products' => 'DSPI0005', 'quantities' => '1', 'unit_prices' => '10000', 'dispatch_status' => '  Invoice_Sent '])->assertStatus(201);

        $this->assertSame('invoice_sent', Preorder::firstOrFail()->dispatch_status);
    }

    public function test_full_export_round_trips_dispatch_state_and_tolerates_the_read_only_columns(): void
    {
        $this->actingAsOwner();
        $exported = $this->makeDispatchPreorder('DSPR0001', 'courier', 'shipping');
        $original = Preorder::findOrFail($exported['id']);
        $row = $this->exportService()->export([])[0];

        \App\Models\PreorderItem::query()->delete();
        Preorder::query()->delete();

        // Berkas persis seperti hasil ekspor — termasuk kolom created_at/updated_at.
        $path = $this->writeXlsx([array_keys($row), array_values($row)]);
        $file = new UploadedFile($path, 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/preorders/import', ['file' => $file])->assertStatus(201);

        $copy = Preorder::firstOrFail();
        $this->assertSame('shipping', $copy->dispatch_status);
        $this->assertSame($original->shipping_at->format('Y-m-d H:i:s'), $copy->shipping_at->format('Y-m-d H:i:s'));
        // created_at hasil impor = waktu impor, BUKAN nilai dari berkas (kolom itu hanya dibaca).
        $this->assertTrue($copy->created_at->greaterThanOrEqualTo($original->created_at));
    }

    private function writeXlsx(array $rows): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 1));
        }
        $path = storage_path('app/test-preorders-import-'.uniqid().'.xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
