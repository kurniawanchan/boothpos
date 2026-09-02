<?php

namespace App\Http\Controllers\Api;

use App\Exports\MultiSheetArrayExport;
use App\Exports\SheetArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportMasterDataRequest;
use App\Services\MasterDataImportService;
use App\Support\MasterDataSheets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor massal master data dari satu berkas .xlsx berisi empat sheet
 * (PRD 7.15 / F15.2–F15.4, F15.8).
 *
 * Controller hanya: menegakkan validasi berkas, menyimpan unggahannya
 * dengan aman, mendelegasikan ke MasterDataImportService, dan memetakan
 * hasilnya ke status HTTP. Seluruh aturan bisnis impor ada di service.
 */
class MasterDataImportController extends Controller
{
    /**
     * Diperiksa ULANG di luar rule 'mimes' Laravel, dengan membaca isi
     * berkas (finfo), bukan ekstensi/namanya — pola pertahanan berlapis
     * yang sama dengan PaymentProofController::store(). 'application/zip'
     * ikut diterima karena .xlsx MEMANG arsip zip dan sebagian basis data
     * finfo melaporkannya begitu; karena itu pemeriksaan tidak berhenti di
     * MIME, tapi dilanjutkan dengan membuktikan berkasnya benar-benar bisa
     * dibaca sebagai workbook Xlsx.
     */
    private const ALLOWED_MIME = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    public function __construct(private MasterDataImportService $importService) {}

    /**
     * F15.3 — template berisi nama sheet dan judul kolom yang benar,
     * lengkap dengan satu baris contoh. Tanpa ini pemilik toko harus
     * menebak formatnya, dan setiap tebakan yang salah jadi berkas gagal.
     */
    public function template(Request $request): BinaryFileResponse|JsonResponse
    {
        if (! $request->user()->canAccessAnyMenu(['artists', 'categories', 'products', 'stock', 'vendors', 'materials', 'roles', 'users'])) {
            return response()->json(['message' => 'Hanya owner/admin/inventory yang dapat mengunduh template impor.'], 403);
        }

        $sheets = array_map(
            fn (string $sheet) => new SheetArrayExport(
                $sheet,
                MasterDataSheets::headings($sheet),
                [MasterDataSheets::exampleRow($sheet)],
            ),
            MasterDataSheets::ORDER,
        );

        return Excel::download(new MultiSheetArrayExport($sheets), 'template-impor-master-data.xlsx');
    }

    public function store(ImportMasterDataRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $actualMime = $file->getMimeType();
        if (! in_array($actualMime, self::ALLOWED_MIME, true)) {
            return response()->json([
                'message' => 'Tipe berkas tidak didukung. Hanya .xlsx.',
            ], 422);
        }

        // Nama berkas ACAK, bukan nama asli — mencegah path traversal dan
        // nama berkas dari pengguna dipakai sebagai nama di penyimpanan.
        // Disk 'local' bersifat privat (di luar public/), sama seperti
        // bukti pembayaran.
        $storedPath = $file->storeAs('imports', Str::uuid()->toString().'.xlsx', 'local');
        $absolutePath = Storage::disk('local')->path($storedPath);

        // Pembuktian terakhir: berkasnya benar-benar workbook Xlsx, bukan
        // sekadar arsip zip yang kebetulan lolos pemeriksaan MIME.
        if (! (new XlsxReader)->canRead($absolutePath)) {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => 'Berkas bukan workbook .xlsx yang valid.',
            ], 422);
        }

        $result = $this->importService->import(
            absolutePath: $absolutePath,
            user: $request->user(),
            dryRun: $request->boolean('dry_run'),
            originalName: $file->getClientOriginalName(),
            // Task 6 — batch gambar dikirim di field 'images[]' pada request
            // yang sama; MIME masing-masing sudah diperiksa oleh rule
            // ImportMasterDataRequest, konsisten dengan pemeriksaan ganda
            // .xlsx di atas (rule Laravel + pembuktian ulang di controller).
            images: $request->file('images', []),
        );

        // Berkas sumber hanya disimpan bila impornya BENAR-BENAR diterapkan
        // — sebagai jejak audit atas perubahan massal (mitigasi risiko
        // "impor merusak data massal", PRD 9.6). Pratinjau dan berkas yang
        // ditolak tidak meninggalkan sampah di penyimpanan.
        if (! $result['applied']) {
            Storage::disk('local')->delete($storedPath);
        }

        if ($result['errors'] !== []) {
            return response()->json(array_merge([
                'message' => 'Impor dibatalkan: ada baris yang tidak valid. Tidak ada data yang diubah.',
            ], $result), 422);
        }

        return response()->json(array_merge([
            'message' => $result['dry_run']
                ? 'Pratinjau impor: tidak ada galat. Belum ada data yang diubah.'
                : 'Impor berhasil.',
        ], $result));
    }
}
