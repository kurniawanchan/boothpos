<?php

namespace App\Services;

use App\Imports\InvoiceImport;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 019-billing-system (research.md R6') — export/import Invoice lewat SATU
 * workbook mandiri satu-sheet, meniru persis
 * App\Services\PreorderExportImportService: validasi PENUH dulu baru
 * terapkan sekaligus dalam satu transaksi (all-or-nothing), dry_run
 * melewati jalur validasi yang identik untuk pratinjau.
 *
 * Satu baris berkas = satu invoice.
 * - `invoice_number` KOSONG → baris ini adalah CREATE. `company_name`/
 *   `license_name` di-resolve ke Company/License lewat pencarian nama;
 *   `grand_total` TIDAK diimpor (dihitung server dari subtotal-discount,
 *   sama seperti InvoiceController::store()), nomor invoice dibuat lewat
 *   InvoiceService::generateNumber() — jalur penomoran yang sama persis,
 *   bukan implementasi kedua yang bisa mencar.
 * - `invoice_number` TERISI → baris ini adalah UPDATE pada invoice yang
 *   sudah ada (dicari by invoice_number). Invoice yang sudah `paid` tidak
 *   boleh diubah lewat jalur ini (FR-011, mirror InvoiceService::update()'s
 *   guard) — kegagalan satu baris menggagalkan SELURUH impor (all-or-nothing).
 */
class InvoiceExportImportService
{
    private const HEADINGS = [
        'invoice_number', 'company_name', 'license_name', 'subtotal', 'discount',
        'grand_total', 'due_date', 'status', 'payment_information', 'notes',
    ];

    public function __construct(private InvoiceService $invoiceService) {}

    public function export(array $filters): array
    {
        $query = Invoice::query()
            ->with(['company', 'license'])
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['license_id']), fn ($q) => $q->where('license_id', $filters['license_id']))
            ->orderByDesc('created_at');

        return $query->get()->map(fn (Invoice $invoice) => [
            'invoice_number' => $invoice->invoice_number,
            'company_name' => $invoice->company?->name,
            'license_name' => $invoice->license?->name,
            'subtotal' => number_format((float) $invoice->subtotal, 2, '.', ''),
            'discount' => number_format((float) $invoice->discount, 2, '.', ''),
            'grand_total' => number_format((float) $invoice->grand_total, 2, '.', ''),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status,
            'payment_information' => $invoice->payment_information,
            'notes' => $invoice->notes,
            'created_at' => $invoice->created_at?->toDateTimeString(),
        ])->all();
    }

    public function template(): array
    {
        return [array_combine(self::HEADINGS, self::HEADINGS)];
    }

    /**
     * @return array{applied: bool, dry_run: bool, created_count: int, updated_count: int, invoice_ids: int[], row_errors: array}
     */
    public function import(UploadedFile $file, bool $dryRun, User $importedBy): array
    {
        $rows = Excel::toArray(new InvoiceImport, $file)[0] ?? [];

        $rowErrors = [];
        $creates = [];
        $updates = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // +1 for 0-index, +1 for the heading row itself
            $errors = [];

            $invoiceNumber = trim((string) ($row['invoice_number'] ?? ''));
            $subtotal = $row['subtotal'] ?? null;
            $discount = $row['discount'] ?? null;
            $dueDate = trim((string) ($row['due_date'] ?? ''));
            $paymentInformation = trim((string) ($row['payment_information'] ?? '')) ?: null;
            $notes = trim((string) ($row['notes'] ?? '')) ?: null;

            if ($invoiceNumber === '') {
                // CREATE — company_name/license_name wajib bisa di-resolve.
                $companyName = trim((string) ($row['company_name'] ?? ''));
                $licenseName = trim((string) ($row['license_name'] ?? ''));

                $company = $companyName !== '' ? Company::where('name', $companyName)->first() : null;
                if ($companyName === '') {
                    $errors[] = __('invoices.import_company_required', ['row' => $rowNumber]);
                } elseif (! $company) {
                    $errors[] = __('invoices.import_company_not_found', ['row' => $rowNumber, 'name' => $companyName]);
                }

                $license = $licenseName !== '' ? License::where('name', $licenseName)->first() : null;
                if ($licenseName === '') {
                    $errors[] = __('invoices.import_license_required', ['row' => $rowNumber]);
                } elseif (! $license) {
                    $errors[] = __('invoices.import_license_not_found', ['row' => $rowNumber, 'name' => $licenseName]);
                }

                if ($subtotal === null || $subtotal === '' || (float) $subtotal <= 0) {
                    $errors[] = __('invoices.import_subtotal_invalid', ['row' => $rowNumber]);
                }

                if ($dueDate === '') {
                    $errors[] = __('invoices.import_due_date_required', ['row' => $rowNumber]);
                }

                if ($errors !== []) {
                    $rowErrors[] = ['row' => $rowNumber, 'errors' => $errors];

                    continue;
                }

                $subtotalValue = (float) $subtotal;
                $discountValue = (float) ($discount ?? 0);

                $creates[] = [
                    'company_id' => $company->id,
                    'license_id' => $license->id,
                    'subtotal' => $subtotalValue,
                    'discount' => $discountValue,
                    'grand_total' => $subtotalValue - $discountValue,
                    'due_date' => $dueDate,
                    'payment_information' => $paymentInformation,
                    'notes' => $notes,
                ];

                continue;
            }

            // UPDATE — invoice_number harus resolve ke invoice yang ada,
            // dan invoice tersebut tidak boleh sudah `paid` (FR-011).
            $invoice = Invoice::where('invoice_number', $invoiceNumber)->first();

            if (! $invoice) {
                $rowErrors[] = ['row' => $rowNumber, 'errors' => [
                    __('invoices.import_invoice_not_found', ['row' => $rowNumber, 'invoice_number' => $invoiceNumber]),
                ]];

                continue;
            }

            if ($invoice->status === 'paid') {
                $rowErrors[] = ['row' => $rowNumber, 'errors' => [
                    __('invoices.import_update_paid_blocked', ['row' => $rowNumber, 'invoice_number' => $invoiceNumber]),
                ]];

                continue;
            }

            if ($subtotal === null || $subtotal === '' || (float) $subtotal <= 0) {
                $errors[] = __('invoices.import_subtotal_invalid', ['row' => $rowNumber]);
            }

            if ($dueDate === '') {
                $errors[] = __('invoices.import_due_date_required', ['row' => $rowNumber]);
            }

            if ($errors !== []) {
                $rowErrors[] = ['row' => $rowNumber, 'errors' => $errors];

                continue;
            }

            $subtotalValue = (float) $subtotal;
            $discountValue = (float) ($discount ?? 0);

            $updates[] = [
                'invoice' => $invoice,
                'subtotal' => $subtotalValue,
                'discount' => $discountValue,
                'grand_total' => $subtotalValue - $discountValue,
                'due_date' => $dueDate,
                'payment_information' => $paymentInformation ?? $invoice->payment_information,
                'notes' => $notes,
            ];
        }

        if ($rowErrors !== []) {
            return [
                'applied' => false, 'dry_run' => $dryRun,
                'created_count' => 0, 'updated_count' => 0, 'invoice_ids' => [],
                'row_errors' => $rowErrors,
            ];
        }

        if ($dryRun) {
            return [
                'applied' => false, 'dry_run' => true,
                'created_count' => count($creates), 'updated_count' => count($updates), 'invoice_ids' => [],
                'row_errors' => [],
            ];
        }

        $invoiceIds = [];

        DB::transaction(function () use ($creates, $updates, &$invoiceIds) {
            foreach ($creates as $data) {
                $invoice = Invoice::create([
                    'invoice_number' => $this->invoiceService->generateNumber(),
                    'company_id' => $data['company_id'],
                    'license_id' => $data['license_id'],
                    'subtotal' => $data['subtotal'],
                    'discount' => $data['discount'],
                    'grand_total' => $data['grand_total'],
                    'due_date' => $data['due_date'],
                    'payment_information' => $data['payment_information'],
                    'notes' => $data['notes'],
                    'status' => 'unpaid',
                ]);

                $invoiceIds[] = $invoice->id;
            }

            foreach ($updates as $data) {
                $data['invoice']->update([
                    'subtotal' => $data['subtotal'],
                    'discount' => $data['discount'],
                    'grand_total' => $data['grand_total'],
                    'due_date' => $data['due_date'],
                    'payment_information' => $data['payment_information'],
                    'notes' => $data['notes'],
                ]);

                $invoiceIds[] = $data['invoice']->id;
            }
        });

        return [
            'applied' => true, 'dry_run' => false,
            'created_count' => count($creates), 'updated_count' => count($updates),
            'invoice_ids' => $invoiceIds, 'row_errors' => [],
        ];
    }
}
