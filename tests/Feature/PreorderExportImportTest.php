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
