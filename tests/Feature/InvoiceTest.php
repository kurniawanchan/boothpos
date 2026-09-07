<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\License;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    public function test_owner_can_create_invoice_with_full_field_shape(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();
        $license = License::factory()->create();

        $response = $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 1000000,
            'discount' => 100000,
            'due_date' => now()->addDays(14)->toDateString(),
            'payment_information' => 'BCA 123456789 a.n. BoothPOS',
            'notes' => 'Invoice pertama',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'unpaid')
            ->assertJsonPath('subtotal', '1000000.00')
            ->assertJsonPath('discount', '100000.00')
            ->assertJsonPath('grand_total', '900000.00')
            ->assertJsonPath('license_id', $license->id)
            ->assertJsonPath('license.id', $license->id)
            ->assertJsonPath('payment_information', 'BCA 123456789 a.n. BoothPOS');

        $this->assertNotEmpty($response->json('invoice_number'));
        $this->assertStringStartsWith('INV-'.now()->format('Ym').'-', $response->json('invoice_number'));

        $this->assertDatabaseHas('invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'status' => 'unpaid',
        ]);
    }

    public function test_discount_exceeding_subtotal_is_rejected(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();
        $license = License::factory()->create();

        $response = $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 100000,
            'discount' => 200000,
            'due_date' => now()->addDays(14)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('discount');
    }

    public function test_owner_can_update_an_unpaid_invoice(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);
        $newLicense = License::factory()->create();

        $response = $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'company_id' => $invoice->company_id,
            'license_id' => $newLicense->id,
            'subtotal' => 500000,
            'discount' => 50000,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertOk()
            ->assertJsonPath('subtotal', '500000.00')
            ->assertJsonPath('grand_total', '450000.00')
            ->assertJsonPath('license_id', $newLicense->id);
    }

    public function test_updating_a_paid_invoice_is_rejected_with_409(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'paid', 'paid_at' => now()]);

        $response = $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'company_id' => $invoice->company_id,
            'license_id' => $invoice->license_id,
            'subtotal' => 500000,
            'discount' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertStatus(409);
    }

    public function test_deleting_a_paid_invoice_is_rejected_with_409(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'paid', 'paid_at' => now()]);

        $response = $this->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_deleting_an_unpaid_invoice_succeeds(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);

        $response = $this->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    }

    public function test_deleting_a_cancelled_invoice_succeeds(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'cancelled']);

        $response = $this->deleteJson("/api/v1/invoices/{$invoice->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    }

    public function test_owner_can_mark_invoice_paid(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/mark-paid");

        $response->assertOk()->assertJsonPath('status', 'paid');
        $this->assertNotNull($invoice->fresh()->paid_at);
    }

    public function test_marking_an_already_paid_invoice_paid_again_is_rejected(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'paid', 'paid_at' => now()]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/mark-paid");

        $response->assertStatus(409);
    }

    public function test_owner_can_cancel_an_unpaid_invoice(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/cancel");

        $response->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_cancelling_an_already_cancelled_invoice_is_rejected(): void
    {
        $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'cancelled']);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/cancel");

        $response->assertStatus(409);
    }

    public function test_invoice_list_can_be_filtered_by_company(): void
    {
        $this->actingAsOwner();
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        Invoice::factory()->create(['company_id' => $companyA->id]);
        Invoice::factory()->create(['company_id' => $companyB->id]);

        $response = $this->getJson("/api/v1/invoices?company_id={$companyA->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_cashier_cannot_manage_invoices(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $company = Company::factory()->create();
        $license = License::factory()->create();

        $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 100000,
            'due_date' => now()->toDateString(),
        ])->assertStatus(403);
        $this->getJson('/api/v1/invoices')->assertStatus(403);
    }

    /**
     * Cross-mode uniqueness (T060, mirroring PreorderTest/OrderTest's own
     * pattern for order_number/preorder_number) — invoice_number carries a
     * database-wide UNIQUE constraint even though Invoice stays
     * HasDataMode-scoped (research.md R3'), so two invoices created in
     * different modes within the same calendar month must never collide.
     */
    public function test_invoice_number_is_unique_across_demo_and_live_modes(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();
        $license = License::factory()->create();

        $liveInvoice = ModeGate::runAs('live', fn () => $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 100000,
            'due_date' => now()->addDays(7)->toDateString(),
        ]))->assertCreated();

        $demoInvoice = ModeGate::runAs('demo', fn () => $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 200000,
            'due_date' => now()->addDays(7)->toDateString(),
        ]))->assertCreated();

        $this->assertNotEquals($liveInvoice->json('invoice_number'), $demoInvoice->json('invoice_number'));
        $this->assertEquals(2, \App\Models\Invoice::withoutGlobalScope(\App\Models\Concerns\DataModeScope::class)->count());
    }

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI (019-billing-system, second
     * expansion) — ditemukan lewat verifikasi manual browser sungguhan:
     * generateNumber() sebelumnya hanya menghitung invoice yang belum
     * di-soft-delete, padahal UNIQUE constraint invoice_number di level
     * database tetap berlaku untuk baris yang sudah dihapus. Akibatnya
     * invoice baru bisa mendapat nomor yang sudah "dipakai" oleh invoice
     * yang sudah dihapus, menyebabkan 1062 Duplicate entry saat insert.
     */
    public function test_generating_invoice_number_accounts_for_soft_deleted_invoices(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create();
        $license = License::factory()->create();

        $first = $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 100000,
            'due_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/invoices/{$first->json('id')}")->assertNoContent();

        $second = $this->postJson('/api/v1/invoices', [
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 200000,
            'due_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $this->assertNotEquals($first->json('invoice_number'), $second->json('invoice_number'));
    }

    /**
     * research.md R12 (T095) — company.business_type dibaca live dari relasi
     * Company::businessType(), bukan snapshot: GET /invoices/{invoice} harus
     * menyertakan nama business type milik company terkait.
     */
    public function test_show_invoice_includes_company_business_type(): void
    {
        $this->actingAsOwner();
        $businessType = BusinessType::factory()->create(['name' => 'Retail']);
        $company = Company::factory()->create(['business_type_id' => $businessType->id]);
        $invoice = Invoice::factory()->create(['company_id' => $company->id]);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk()
            ->assertJsonPath('company.business_type.id', $businessType->id)
            ->assertJsonPath('company.business_type.name', 'Retail');
    }

    public function test_summary_returns_correct_unpaid_and_paid_totals(): void
    {
        $this->actingAsOwner();
        Invoice::factory()->create(['status' => 'unpaid', 'subtotal' => 100000, 'discount' => 0, 'grand_total' => 100000]);
        Invoice::factory()->create(['status' => 'unpaid', 'subtotal' => 200000, 'discount' => 0, 'grand_total' => 200000]);
        Invoice::factory()->create(['status' => 'paid', 'paid_at' => now(), 'subtotal' => 500000, 'discount' => 0, 'grand_total' => 500000]);
        Invoice::factory()->create(['status' => 'cancelled', 'subtotal' => 999999, 'discount' => 0, 'grand_total' => 999999]);

        $response = $this->getJson('/api/v1/invoices/summary');

        $response->assertOk()
            ->assertJsonPath('unpaid.count', 2)
            ->assertJsonPath('unpaid.total', '300000.00')
            ->assertJsonPath('paid.count', 1)
            ->assertJsonPath('paid.total', '500000.00')
            ->assertJsonPath('overall_count', 4);
    }

    // ------------------------------------------------------------------
    // 019-billing-system (T074/T077) — export/import Invoice, mirroring
    // PreorderExportImportServiceTest's own coverage pattern.
    // ------------------------------------------------------------------

    public function test_export_produces_expected_rows_for_known_invoices(): void
    {
        $this->actingAsOwner();
        $company = Company::factory()->create(['name' => 'PT Contoh Jaya']);
        $license = License::factory()->create(['name' => 'Master']);
        Invoice::factory()->create([
            'company_id' => $company->id,
            'license_id' => $license->id,
            'subtotal' => 1000000,
            'discount' => 0,
            'grand_total' => 1000000,
            'status' => 'unpaid',
        ]);

        $rows = app(\App\Services\InvoiceExportImportService::class)->export([]);

        $this->assertCount(1, $rows);
        $this->assertSame('PT Contoh Jaya', $rows[0]['company_name']);
        $this->assertSame('Master', $rows[0]['license_name']);
        $this->assertSame('1000000.00', $rows[0]['subtotal']);
        $this->assertSame('1000000.00', $rows[0]['grand_total']);
        $this->assertSame('unpaid', $rows[0]['status']);
    }

    public function test_import_creates_new_invoice_with_generated_number(): void
    {
        $owner = $this->actingAsOwner();
        $company = Company::factory()->create(['name' => 'PT Baru']);
        $license = License::factory()->create(['name' => 'Pro']);

        $result = app(\App\Services\InvoiceExportImportService::class)->import(
            $this->makeInvoiceUploadFile([
                [
                    'invoice_number' => '',
                    'company_name' => 'PT Baru',
                    'license_name' => 'Pro',
                    'subtotal' => 500000,
                    'discount' => 0,
                    'grand_total' => '',
                    'due_date' => now()->addDays(7)->toDateString(),
                    'status' => '',
                    'payment_information' => '',
                    'notes' => 'dari impor',
                ],
            ]),
            false,
            $owner,
        );

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['created_count']);

        $invoice = Invoice::findOrFail($result['invoice_ids'][0]);
        $this->assertStringStartsWith('INV-'.now()->format('Ym').'-', $invoice->invoice_number);
        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame($license->id, $invoice->license_id);
        $this->assertEquals(500000, (float) $invoice->grand_total);
    }

    public function test_import_updates_existing_invoice_by_invoice_number(): void
    {
        $owner = $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'unpaid', 'subtotal' => 100000, 'discount' => 0, 'grand_total' => 100000]);

        $result = app(\App\Services\InvoiceExportImportService::class)->import(
            $this->makeInvoiceUploadFile([
                [
                    'invoice_number' => $invoice->invoice_number,
                    'company_name' => '',
                    'license_name' => '',
                    'subtotal' => 300000,
                    'discount' => 50000,
                    'grand_total' => '',
                    'due_date' => now()->addDays(10)->toDateString(),
                    'status' => '',
                    'payment_information' => 'updated via import',
                    'notes' => '',
                ],
            ]),
            false,
            $owner,
        );

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['updated_count']);

        $invoice->refresh();
        $this->assertEquals(300000, (float) $invoice->subtotal);
        $this->assertEquals(250000, (float) $invoice->grand_total);
        $this->assertSame('updated via import', $invoice->payment_information);
    }

    public function test_import_rejects_updating_a_paid_invoice_and_saves_nothing(): void
    {
        $owner = $this->actingAsOwner();
        $invoice = Invoice::factory()->create(['status' => 'paid', 'paid_at' => now(), 'subtotal' => 100000, 'discount' => 0, 'grand_total' => 100000]);
        $originalSubtotal = (float) $invoice->subtotal;
        $countBefore = Invoice::count();

        $result = app(\App\Services\InvoiceExportImportService::class)->import(
            $this->makeInvoiceUploadFile([
                [
                    'invoice_number' => $invoice->invoice_number,
                    'company_name' => '',
                    'license_name' => '',
                    'subtotal' => 999999,
                    'discount' => 0,
                    'grand_total' => '',
                    'due_date' => now()->addDays(10)->toDateString(),
                    'status' => '',
                    'payment_information' => '',
                    'notes' => '',
                ],
            ]),
            false,
            $owner,
        );

        $this->assertFalse($result['applied']);
        $this->assertNotEmpty($result['row_errors']);

        $invoice->refresh();
        $this->assertEquals($originalSubtotal, (float) $invoice->subtotal);
        $this->assertSame($countBefore, Invoice::count());
    }

    public function test_import_rejects_row_referencing_nonexistent_company_or_license(): void
    {
        $owner = $this->actingAsOwner();
        $countBefore = Invoice::count();

        $result = app(\App\Services\InvoiceExportImportService::class)->import(
            $this->makeInvoiceUploadFile([
                [
                    'invoice_number' => '',
                    'company_name' => 'Perusahaan Tidak Ada',
                    'license_name' => 'Lisensi Tidak Ada',
                    'subtotal' => 100000,
                    'discount' => 0,
                    'grand_total' => '',
                    'due_date' => now()->addDays(7)->toDateString(),
                    'status' => '',
                    'payment_information' => '',
                    'notes' => '',
                ],
            ]),
            false,
            $owner,
        );

        $this->assertFalse($result['applied']);
        $this->assertNotEmpty($result['row_errors']);
        $this->assertSame($countBefore, Invoice::count());
    }

    /**
     * Builds a real .xlsx UploadedFile from an array of associative rows,
     * mirroring PreorderExportImportTest::writeXlsx() exactly (PhpSpreadsheet
     * directly, no Excel::store round-trip needed).
     */
    private function makeInvoiceUploadFile(array $rows): \Illuminate\Http\UploadedFile
    {
        $headings = ['invoice_number', 'company_name', 'license_name', 'subtotal', 'discount', 'grand_total', 'due_date', 'status', 'payment_information', 'notes'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headings, null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray(array_map(fn ($h) => $row[$h] ?? '', $headings), null, 'A'.($i + 2));
        }

        $path = storage_path('app/test-invoices-import-'.uniqid().'.xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'invoices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
