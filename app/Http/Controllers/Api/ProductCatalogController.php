<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductCatalogExport;
use App\Http\Controllers\Controller;
use App\Services\CatalogTooLargeException;
use App\Services\ProductCatalogBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Excel;

/**
 * 049 — katalog varian per penjual. Gerbang SISI SERVER: menu `products` (sama dengan tombolnya di
 * layar Produk). Sengaja BUKAN canManageMasterData() seperti /exports/{entity}: katalog hanya memuat
 * data jual (harga jual, gambar, kode), tanpa modal/stok, dan spesifikasinya memberikannya kepada
 * siapa pun yang boleh membuka layar Produk.
 */
class ProductCatalogController extends Controller
{
    public function __construct(private ProductCatalogBuilder $builder) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('products')) {
            return response()->json(['message' => __('catalog.not_authorized')], 403);
        }

        try {
            return response()->json($this->builder->build($request->only(['search', 'artist_id', 'category_id'])));
        } catch (CatalogTooLargeException $e) {
            return response()->json(['message' => __('catalog.too_large', ['max' => $e->max])], 422);
        }
    }

    /**
     * Excel: datanya dari builder yang sama dengan JSON/PDF (withImagePaths hanya untuk membaca gambar dari
     * disk). Dibangun utuh lewat Excel::raw() supaya berkas gambar sementara bisa DIHAPUS segera setelah
     * workbook selesai ditulis — Excel::download() menyimpan objek ekspor sampai respons terkirim.
     */
    public function export(Request $request): JsonResponse|Response
    {
        if (! $request->user()->canAccessMenu('products')) {
            return response()->json(['message' => __('catalog.not_authorized')], 403);
        }

        try {
            $catalog = $this->builder->build($request->only(['search', 'artist_id', 'category_id']), withImagePaths: true);
        } catch (CatalogTooLargeException $e) {
            return response()->json(['message' => __('catalog.too_large', ['max' => $e->max])], 422);
        }

        if ($catalog['totals']['variants'] === 0) {
            return response()->json(['message' => __('catalog.nothing_to_export')], 422); // tanpa berkas kosong
        }

        $this->ensureMemoryForWorkbook();

        $export = new ProductCatalogExport($catalog);
        try {
            $content = app(Excel::class)->raw($export, Excel::XLSX);
        } finally {
            $export->cleanup();
        }

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="katalog-produk.xlsx"',
        ]);
    }

    /**
     * Workbook dengan ratusan gambar tertanam tidak muat di batas 128 MB bawaan PHP (yang sudah pernah
     * menjatuhkan ekspor lain). Dinaikkan hanya untuk permintaan INI, dan hanya bila batas saat ini lebih
     * kecil — tidak pernah menurunkan atau menyentuh konfigurasi global.
     */
    private function ensureMemoryForWorkbook(): void
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return;
        }
        $bytes = (int) $limit * match (strtoupper(substr((string) $limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
        if ($bytes < 512 * 1024 ** 2) {
            @ini_set('memory_limit', '512M');
        }
    }
}
