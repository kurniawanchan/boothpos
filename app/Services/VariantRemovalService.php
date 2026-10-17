<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\PreorderItem;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hapus varian dari drawer produk (spec 045, FR-015/FR-016).
 *
 * - Varian TANPA riwayat (penjualan, pre-order, mutasi stok, baris BOM) dihapus
 *   lunak. SKU-nya diganti penanda sementara supaya nomornya bebas dipakai lagi
 *   (indeks unik sku tetap berlaku untuk baris yang dihapus). Snapshot penjualan
 *   lama tidak tersentuh: ia menyimpan teks SKU sendiri.
 * - Varian DENGAN riwayat tidak dihapus; hanya dinonaktifkan seperti sebelumnya.
 *
 * Riwayat dihitung lintas mode (DEMO dan LIVE) karena SKU unik di seluruh toko.
 */
class VariantRemovalService
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /** @return bool true = dihapus; false = punya riwayat, hanya dinonaktifkan. */
    public function remove(ProductVariant $variant, ?User $user): bool
    {
        return DB::transaction(function () use ($variant, $user) {
            $locked = ProductVariant::whereKey($variant->id)->lockForUpdate()->firstOrFail();

            if ($this->hasHistory($locked)) {
                $locked->update(['is_active' => false]);

                $this->activityLogger->log(
                    userId: $user?->id,
                    action: 'variant_deactivated',
                    entityType: 'ProductVariant',
                    entityId: $locked->id,
                    description: "Menonaktifkan varian {$locked->sku} karena sudah punya riwayat.",
                    oldValues: ['is_active' => true],
                    newValues: ['is_active' => false],
                );

                return false;
            }

            $oldSku = $locked->sku;
            $locked->update(['sku' => '#'.$locked->id]);
            $locked->delete();

            $this->activityLogger->log(
                userId: $user?->id,
                action: 'variant_deleted',
                entityType: 'ProductVariant',
                entityId: $locked->id,
                description: "Menghapus varian {$oldSku}.",
                oldValues: ['sku' => $oldSku],
                newValues: null,
            );

            return true;
        });
    }

    private function hasHistory(ProductVariant $variant): bool
    {
        return OrderItem::withoutGlobalScopes()->where('variant_id', $variant->id)->exists()
            || PreorderItem::withoutGlobalScopes()->where('variant_id', $variant->id)->exists()
            || StockMovement::withoutGlobalScopes()->where('variant_id', $variant->id)->exists()
            || ProductVariantBomLine::withoutGlobalScopes()->where('product_variant_id', $variant->id)->exists();
    }
}
