<?php

namespace App\Http\Controllers\Api;

use App\Exports\GenericArrayExport;
use App\Http\Controllers\Controller;
use App\Services\InvoiceExportImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 019-billing-system (T074/T075) — export/import Invoice dipisah dari
 * InvoiceController ke controller sendiri secara sengaja, semata untuk
 * menghindari konflik berkas dengan InvoiceController yang bersamaan
 * sedang disunting oleh pekerjaan lain (bukan pola arsitektur baru —
 * PreorderController menaruh export/import sebagai method tambahan pada
 * controller yang sama; lihat catatan di plan-nya).
 *
 * Gate sama seperti PreorderController::export()/import(): inline
 * isOwnerOrAdmin(), bukan menu key baru atau policy baru.
 */
class InvoiceExportImportController extends Controller
{
    public function __construct(private InvoiceExportImportService $exportImportService) {}

    public function export(Request $request)
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('invoices.not_authorized'));

        $rows = $this->exportImportService->export($request->only(['status', 'company_id', 'license_id']));

        return Excel::download(new GenericArrayExport($rows), 'invoices.xlsx');
    }

    public function importTemplate(Request $request)
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('invoices.not_authorized'));

        return Excel::download(new GenericArrayExport($this->exportImportService->template()), 'template-invoices.xlsx');
    }

    public function import(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('invoices.not_authorized'));

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx']]);

        $result = $this->exportImportService->import($request->file('file'), $request->boolean('dry_run'), $request->user());

        if (! $result['applied'] && ! $result['dry_run']) {
            return response()->json([
                'message' => __('invoices.import_nothing_saved'),
                'row_errors' => $result['row_errors'],
            ], 409);
        }

        return response()->json([
            'created_count' => $result['created_count'],
            'updated_count' => $result['updated_count'],
            'invoice_ids' => $result['invoice_ids'],
        ], $result['dry_run'] ? 200 : 201);
    }
}
