<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ProductSegment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ganti segmen produk (spec 044, FR-006/FR-007).
 *
 * Mengubah segmen mengubah kode produk, sehingga SEMUA SKU varian (termasuk yang
 * dihapus) ikut berubah. Setiap varian mempertahankan nomornya: `SPF-SH-SH-1`
 * menjadi `SPF-SH-S-1`. Nomor diambil dari SKU lama lewat sequenceOf(); bila
 * nomor sudah dipakai varian lain di produk yang sama (hanya mungkin pada data
 * lama), varian itu mengambil nomor kosong berikutnya.
 *
 * Semua dalam SATU transaksi dengan kunci baris produk, dan perubahan dicatat di
 * activity log dalam transaksi yang sama. Riwayat (order_items/preorder_items
 * sku_snapshot, stok) TIDAK disentuh — itu catatan historis.
 */
class ProductSegmentChangeService
{
    public function __construct(
        private ProductCodeGenerator $codeGenerator,
        private ActivityLogger $activityLogger,
    ) {}

    public function changeSegment(Product $product, ?string $segment, ?User $user): void
    {
        DB::transaction(function () use ($product, $segment, $user) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $normalised = ProductSegment::normalise($segment);
            if ($normalised === null || $normalised === $locked->product_segment) {
                return;
            }

            // Memakai aturan kode yang sama dengan pembuatan produk. Kode yang sudah
            // dipakai produk lain (lintas mode, termasuk yang dihapus) ditolak di sini
            // dengan 422 pada product_segment; transaksi ikut dibatalkan.
            $oldPrefix = $locked->code_prefix;
            $newPrefix = $this->codeGenerator->buildCodePrefix(
                $locked->artist->code,
                $locked->category->code,
                $normalised,
            );

            // Varian yang sudah dihapus tidak diregenerasi (spec 045, FR-016).
            // Varian nonaktif tidak diregenerasi, sama seperti yang dihapus (spec 045).
            $variants = ProductVariant::query()
                ->where('product_id', $locked->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            // SEMUA SKU aktif ikut kode baru, termasuk yang diketik pemilik: yang diketik
            // hanya nomornya, kodenya selalu milik produk (spec 045, FR-010 — diubah
            // 2026-10-17). Nomor tiap varian dipertahankan; bentrok diselesaikan assignSkus().
            // Nomor yang DIKETIK pemilik didahulukan saat bentrok: yang dibuat server
            // yang bergeser ke nomor kosong berikutnya (spec 045).
            $ordered = $variants->sortBy(fn (ProductVariant $v) => $v->sku_is_typed ? 0 : 1)->values();
            $newSkus = $this->assignSkus($ordered, $oldPrefix, $newPrefix);

            // Spec 046, FR-004: segmen yang membuat SKU melebihi lebar kolom ditolak di
            // sini (pada segmen, dengan pesan), sebelum ada yang ditulis. Lewat exception
            // transaksi ikut dibatalkan.
            if (collect($newSkus)->contains(fn (string $sku) => strlen($sku) > ProductSegment::MAX_SKU_LENGTH)) {
                throw ValidationException::withMessages([
                    'product_segment' => ['Segmen ini membuat SKU lebih dari '.ProductSegment::MAX_SKU_LENGTH.' karakter. Gunakan segmen yang lebih pendek.'],
                ]);
            }

            $locked->update([
                'code_prefix' => $newPrefix,
                'product_segment' => $normalised,
            ]);

            foreach ($variants as $variant) {
                ProductVariant::withTrashed()->whereKey($variant->id)->update(['sku' => $newSkus[$variant->id]]);
            }

            $this->activityLogger->log(
                userId: $user?->id,
                action: 'segment_changed',
                entityType: 'Product',
                entityId: $locked->id,
                description: "Mengubah segmen produk {$locked->name}: {$oldPrefix} menjadi {$newPrefix} (".count($newSkus).' SKU diperbarui).',
                oldValues: ['code_prefix' => $oldPrefix],
                newValues: ['code_prefix' => $newPrefix, 'sku_count' => count($newSkus)],
            );
        });
    }

    /**
     * SKU baru per id varian. Nomor dari SKU lama dipakai bila masih unik dalam
     * produk; yang bentrok (atau tidak punya nomor) mengambil nomor kosong berikutnya.
     *
     * @return array<int, string> id varian => SKU baru
     */
    private function assignSkus($variants, string $oldPrefix, string $newPrefix): array
    {
        $numbers = [];
        $taken = [];

        foreach ($variants as $variant) {
            // SKU diketik bisa memakai kode lama lain (mis. `…-MCY-1` setelah ganti segmen
            // sebelumnya): nomornya diambil dari ujung SKU (trailingNumber), spec 045.
            $n = $this->codeGenerator->sequenceOf($oldPrefix, $variant->sku)
                ?? $this->codeGenerator->trailingNumber($variant->sku);
            if ($n !== null && ! isset($taken[$n])) {
                $numbers[$variant->id] = $n;
                $taken[$n] = true;
            }
        }

        $next = $taken === [] ? 1 : max(array_keys($taken)) + 1;

        $skus = [];
        foreach ($variants as $variant) {
            if (! isset($numbers[$variant->id])) {
                while (isset($taken[$next])) {
                    $next++;
                }
                $numbers[$variant->id] = $next;
                $taken[$next] = true;
            }
            $skus[$variant->id] = $this->codeGenerator->variantSku($newPrefix, $numbers[$variant->id]);
        }

        return $skus;
    }
}
