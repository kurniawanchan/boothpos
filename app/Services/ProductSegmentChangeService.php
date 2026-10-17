<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ProductSegment;
use Illuminate\Support\Facades\DB;

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

            $variants = ProductVariant::withTrashed()
                ->where('product_id', $locked->id)
                ->orderBy('id')
                ->get();

            $newSkus = $this->assignSkus($variants, $oldPrefix, $newPrefix);

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
            $n = $this->codeGenerator->sequenceOf($oldPrefix, $variant->sku);
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
