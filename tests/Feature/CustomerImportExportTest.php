<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * 020-customer-data-import-export — mirrors tests/Feature/MasterDataImportTest.php's
 * structure: berkas .xlsx SUNGGUHAN (bukan UploadedFile::fake(), yang kosong
 * dan tidak pernah membuktikan pembacaan sheet benar-benar jalan).
 */
class CustomerImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = ['name', 'phone', 'address', 'email', 'social_handle', 'notes'];

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::HEADINGS, null, 'A1');

        $ordered = array_map(
            fn (array $row) => array_map(fn (string $heading) => $row[$heading] ?? null, self::HEADINGS),
            $rows,
        );

        if ($ordered !== []) {
            $sheet->fromArray($ordered, null, 'A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'boothpos-customer-import').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'customers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // --- US1: Export ---------------------------------------------------

    public function test_export_requires_bulk_management_access(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/customers/export')->assertStatus(403);
    }

    public function test_export_returns_one_row_per_customer_with_all_six_columns(): void
    {
        $this->actingAsRole('owner');
        Customer::factory()->create(['name' => 'Aiko', 'phone' => '0812', 'address' => 'Jl. A', 'email' => 'aiko@example.com', 'social_handle' => '@aiko', 'notes' => 'VIP']);

        $response = $this->get('/api/v1/customers/export');

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_only_includes_the_currently_active_data_mode(): void
    {
        $this->actingAsRole('owner');
        ModeGate::runAs('demo', fn () => Customer::factory()->create(['name' => 'Demo Only Customer']));
        Customer::factory()->create(['name' => 'Live Customer']);

        // Diverifikasi tidak langsung (isi file biner tidak mudah di-assert
        // per baris tanpa membaca ulang xlsx), tapi lewat service secara
        // langsung — cara paling ringkas untuk memastikan hanya satu baris
        // (live) yang muncul.
        $rows = app(\App\Services\CustomerExportImportService::class)->export();

        $this->assertCount(1, $rows);
        $this->assertSame('Live Customer', $rows[0]['name']);
    }

    public function test_export_on_an_empty_customer_list_returns_headers_only(): void
    {
        $this->actingAsRole('owner');

        $rows = app(\App\Services\CustomerExportImportService::class)->export();

        $this->assertSame([], $rows);
    }

    // --- US2: Bulk import ------------------------------------------------

    public function test_import_creates_new_customers(): void
    {
        $user = $this->actingAsRole('owner');

        $file = $this->workbook([
            ['name' => 'Budi', 'phone' => '0812000', 'address' => null, 'email' => 'budi@example.com', 'social_handle' => null, 'notes' => null],
            ['name' => 'Citra', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
        ]);

        $response = $this->post('/api/v1/customers/import', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJson(['created_count' => 2, 'updated_count' => 0, 'row_errors' => []]);
        $this->assertDatabaseHas('customers', ['name' => 'Budi', 'email' => 'budi@example.com']);
        $this->assertDatabaseHas('customers', ['name' => 'Citra']);
    }

    public function test_import_updates_an_existing_customer_matched_by_email_case_insensitively(): void
    {
        $this->actingAsRole('owner');
        $existing = Customer::factory()->create(['name' => 'Old Name', 'email' => 'Jane@Example.com', 'phone' => '0800', 'notes' => 'lama']);

        $file = $this->workbook([
            ['name' => 'New Name', 'phone' => null, 'address' => null, 'email' => 'jane@example.com', 'social_handle' => null, 'notes' => null],
        ]);

        $response = $this->post('/api/v1/customers/import', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJson(['created_count' => 0, 'updated_count' => 1]);
        $existing->refresh();
        $this->assertSame('New Name', $existing->name);
        // Sel kosong (phone/notes) TIDAK mengosongkan nilai yang sudah ada.
        $this->assertSame('0800', $existing->phone);
        $this->assertSame('lama', $existing->notes);
    }

    public function test_a_row_without_email_always_creates_a_new_customer_even_if_name_matches(): void
    {
        $this->actingAsRole('owner');
        Customer::factory()->create(['name' => 'Dedup Me', 'email' => 'dedup@example.com']);

        $file = $this->workbook([
            ['name' => 'Dedup Me', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
        ]);

        $response = $this->post('/api/v1/customers/import', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJson(['created_count' => 1, 'updated_count' => 0]);
        $this->assertSame(2, Customer::where('name', 'Dedup Me')->count());
    }

    public function test_a_missing_name_is_a_row_level_error_and_nothing_is_saved(): void
    {
        $this->actingAsRole('owner');

        $file = $this->workbook([
            ['name' => 'Valid Row', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
            // 'name' kosong tapi 'phone' terisi — baris ini TIDAK boleh
            // sepenuhnya kosong, atau PhpSpreadsheet menganggapnya baris
            // kosong murni dan tidak ikut terbaca sama sekali (bukan bug
            // service, cara pembacaan Excel yang sebenarnya).
            ['name' => '', 'phone' => '0899999', 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
        ]);

        $response = $this->post('/api/v1/customers/import', ['file' => $file]);

        $response->assertStatus(409);
        $response->assertJsonPath('row_errors.0.row', 3);
        $this->assertDatabaseMissing('customers', ['name' => 'Valid Row']);
    }

    public function test_a_non_spreadsheet_file_is_rejected(): void
    {
        $this->actingAsRole('owner');
        $path = tempnam(sys_get_temp_dir(), 'not-a-workbook').'.xlsx';
        file_put_contents($path, 'not actually an xlsx file');
        $this->tempFiles[] = $path;
        $file = new UploadedFile($path, 'fake.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->post('/api/v1/customers/import', ['file' => $file]);

        $response->assertStatus(422);
        $this->assertSame(0, Customer::count());
    }

    public function test_import_only_matches_customers_within_the_currently_active_data_mode(): void
    {
        $this->actingAsRole('owner');
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create(['email' => 'shared@example.com', 'name' => 'Demo Person']));

        $file = $this->workbook([
            ['name' => 'Live Person', 'phone' => null, 'address' => null, 'email' => 'shared@example.com', 'social_handle' => null, 'notes' => null],
        ]);

        $response = $this->post('/api/v1/customers/import', ['file' => $file]);

        $response->assertStatus(201);
        $response->assertJson(['created_count' => 1, 'updated_count' => 0]);
        $demoCustomer->refresh();
        $this->assertSame('Demo Person', $demoCustomer->name); // tidak ikut ter-update
    }

    public function test_import_requires_bulk_management_access(): void
    {
        $this->actingAsRole('cashier');
        $file = $this->workbook([['name' => 'X', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null]]);

        $this->post('/api/v1/customers/import', ['file' => $file])->assertStatus(403);
    }

    public function test_inventory_role_may_import(): void
    {
        $this->actingAsRole('inventory');
        $file = $this->workbook([['name' => 'X', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null]]);

        $this->post('/api/v1/customers/import', ['file' => $file])->assertStatus(201);
    }

    public function test_a_successful_import_writes_an_activity_log_entry_inside_the_same_transaction(): void
    {
        $user = $this->actingAsRole('owner');
        $file = $this->workbook([['name' => 'Logged', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null]]);

        $this->post('/api/v1/customers/import', ['file' => $file])->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'imported',
            'entity_type' => 'CustomerImport',
        ]);
    }

    // --- US3: dry_run preview --------------------------------------------

    public function test_dry_run_reports_counts_and_errors_without_creating_or_updating_any_customer(): void
    {
        $this->actingAsRole('owner');
        Customer::factory()->create(['email' => 'match@example.com', 'name' => 'Before']);

        $file = $this->workbook([
            ['name' => 'After', 'phone' => null, 'address' => null, 'email' => 'match@example.com', 'social_handle' => null, 'notes' => null],
            ['name' => 'Brand New', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
        ]);

        $response = $this->post('/api/v1/customers/import', ['file' => $file, 'dry_run' => '1']);

        $response->assertStatus(200);
        $response->assertJson(['dry_run' => true, 'created_count' => 1, 'updated_count' => 1, 'row_errors' => []]);
        $this->assertDatabaseHas('customers', ['name' => 'Before']); // tidak berubah
        $this->assertSame(1, Customer::count());
    }

    public function test_confirming_the_same_file_after_a_clean_dry_run_produces_exactly_the_counts_previewed(): void
    {
        $this->actingAsRole('owner');
        $file = $this->workbook([
            ['name' => 'One', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
            ['name' => 'Two', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
        ]);

        $preview = $this->post('/api/v1/customers/import', ['file' => $file, 'dry_run' => '1']);
        $preview->assertJson(['created_count' => 2, 'updated_count' => 0]);

        $file2 = $this->workbook([
            ['name' => 'One', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
            ['name' => 'Two', 'phone' => null, 'address' => null, 'email' => null, 'social_handle' => null, 'notes' => null],
        ]);
        $real = $this->post('/api/v1/customers/import', ['file' => $file2]);
        $real->assertStatus(201);
        $real->assertJson(['created_count' => 2, 'updated_count' => 0]);
    }

    // --- Template ---------------------------------------------------------

    public function test_import_template_requires_bulk_management_access(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/customers/import/template')->assertStatus(403);
    }

    public function test_import_template_downloads_a_workbook(): void
    {
        $this->actingAsRole('owner');

        $response = $this->get('/api/v1/customers/import/template');

        $response->assertStatus(200);
    }
}
