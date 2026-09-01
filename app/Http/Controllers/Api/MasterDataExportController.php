<?php

namespace App\Http\Controllers\Api;

use App\Exports\MultiSheetArrayExport;
use App\Exports\SheetArrayExport;
use App\Http\Controllers\Controller;
use App\Services\MasterDataExportService;
use App\Support\MasterDataSheets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ekspor .xlsx per entitas master data (PRD 7.15 / F15.1).
 *
 * Rute dikelompokkan di bawah /exports/{entity}, bukan /{entity}/export,
 * karena dua alasan: (1) berpasangan simetris dengan /imports/master-data
 * sehingga seluruh permukaan berkas ada di satu tempat, dan (2)
 * /artists/export akan bertabrakan dengan /artists/{artist} dari
 * apiResource kecuali urutan pendaftaran rute dijaga manual — ranjau yang
 * tidak perlu dipasang.
 */
class MasterDataExportController extends Controller
{
    public function __construct(private MasterDataExportService $exportService) {}

    public function show(Request $request, string $entity): BinaryFileResponse|JsonResponse
    {
        if (! in_array($entity, MasterDataExportService::ENTITIES, true)) {
            return response()->json(['message' => 'Entitas ekspor tidak dikenali.'], 404);
        }

        // Digerbang canManageMasterData(), bukan sekadar Policy viewAny
        // yang mengizinkan semua peran membaca daftar. Alasannya area
        // risiko access control: menarik seluruh master data sebagai satu
        // berkas adalah permukaan ekstraksi data massal, sedangkan kasir
        // hanya butuh daftar terpaginasi di layar kasir. Gerbangnya
        // disamakan dengan siapa yang boleh MENGELOLA data ini
        // (owner/admin/inventory), konsisten dengan
        // StockAdjustmentRequest dan ImportMasterDataRequest.
        if (! $request->user()->canManageMasterData()) {
            return response()->json(['message' => 'Hanya owner/admin/inventory yang dapat mengekspor data master.'], 403);
        }

        $sheet = new SheetArrayExport(
            $entity,
            MasterDataSheets::headings($entity),
            $this->exportService->rows($entity),
        );

        // Dibungkus MultiSheetArrayExport meski hanya satu sheet, supaya
        // sheet-nya bernama kanonik ('products', 'stock', ...) dan berkas
        // hasil ekspor bisa langsung diunggah balik ke endpoint impor.
        return Excel::download(
            new MultiSheetArrayExport([$sheet]),
            $this->exportService->filename($entity),
        );
    }
}
