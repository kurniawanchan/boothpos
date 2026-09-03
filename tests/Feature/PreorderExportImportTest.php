<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 007-preorder-import-export-notify (US3) — export/import via a
 * dedicated single-sheet workbook (research.md R3).
 */
class PreorderExportImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeVariant(string $sku): \App\Models\ProductVariant
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);

        return $product->variants()->create(['sku' => $sku, 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
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

    public function test_import_creates_preorders_and_new_customer_at_ordered_status(): void
    {
        $this->actingAsOwner();
        $variant = $this->makeVariant('IMPKYIMP0001');

        $rows = [
            ['customer_name', 'customer_phone', 'customer_email', 'event_id', 'fulfillment', 'sku', 'qty', 'unit_price', 'notes'],
            ['Pelanggan Baru', '0812345', '', '', 'pickup', $variant->sku, 2, 100000, ''],
        ];
        $path = $this->writeXlsx($rows);

        $file = new UploadedFile($path, 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(201);
        $this->assertSame(1, $response->json('created_count'));
        $this->assertSame(1, $response->json('created_customer_count'));

        $preorder = Preorder::findOrFail($response->json('preorder_ids.0'));
        $this->assertSame('ordered', $preorder->status);
        $this->assertSame('0.00', number_format((float) $preorder->paid_amount, 2, '.', ''));
        $this->assertDatabaseHas('customers', ['name' => 'Pelanggan Baru']);
    }

    public function test_import_with_one_bad_row_creates_nothing(): void
    {
        $this->actingAsOwner();
        $variant = $this->makeVariant('IMPKYIMP0002');

        $rows = [
            ['customer_name', 'customer_phone', 'customer_email', 'event_id', 'fulfillment', 'sku', 'qty', 'unit_price', 'notes'],
            ['Pelanggan Baik', '', '', '', 'pickup', $variant->sku, 1, 100000, ''],
            ['Pelanggan Buruk', '', '', '', 'pickup', 'SKU-TIDAK-ADA', 1, 100000, ''],
        ];
        $path = $this->writeXlsx($rows);
        $file = new UploadedFile($path, 'preorders.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/preorders/import', ['file' => $file]);

        $response->assertStatus(409);
        $this->assertNotEmpty($response->json('row_errors'));
        $this->assertDatabaseCount('preorders', 0);
        $this->assertDatabaseMissing('customers', ['name' => 'Pelanggan Baik']);
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
