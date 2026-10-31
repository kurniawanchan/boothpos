<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 2026-10-31 (permintaan pemilik) — hapus varian PERMANEN, hanya untuk varian yang belum pernah dipakai. Aturan
 * lama "varian tersimpan tidak pernah dihapus, hanya dinonaktifkan" tetap berlaku untuk varian yang punya riwayat:
 * pesanan dan buku besar stok harus tetap menunjuk ke varian aslinya (FK `order_items`/`stock_movements` RESTRICT).
 * Turunan tanpa riwayat bisnis (harga outlet, riwayat harga, baris BOM, tautan resep) ikut terhapus lewat CASCADE.
 */
class VariantDeletionService
{
    public function __construct(private ActivityLogger $log) {}

    /** @return string|null kode penolakan (`last_variant`, `variant_in_use`), null = sudah dihapus */
    public function deleteIfUnused(ProductVariant $variant, User $by): ?string
    {
        return DB::transaction(function () use ($variant, $by) {
            $variant = ProductVariant::withTrashed()->lockForUpdate()->findOrFail($variant->id);

            if (ProductVariant::where('product_id', $variant->product_id)->whereKeyNot($variant->id)->doesntExist()) {
                return 'last_variant';
            }

            // Tanpa global scope: riwayat di mode/outlet mana pun tetap menghalangi (FK tidak peduli scope).
            $used = DB::table('order_items')->where('variant_id', $variant->id)->exists()
                || DB::table('preorder_items')->where('variant_id', $variant->id)->exists()
                || DB::table('stock_movements')->where('variant_id', $variant->id)->exists()
                || DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->where('current_stock', '!=', 0)->exists()
                || DB::table('stock_transfer_lines')->where('kind', 'variant')->where('item_id', $variant->id)->exists();
            if ($used) {
                return 'variant_in_use';
            }

            // Saldo nol per outlet (dibuat saat varian dibuat) bukan riwayat, tetapi FK-nya RESTRICT.
            DB::table('variant_outlet_stock')->where('variant_id', $variant->id)->delete();
            $this->log->log(
                userId: $by->id, action: 'variant_deleted', entityType: 'ProductVariant', entityId: $variant->id,
                description: "Varian {$variant->sku} ({$variant->variant_name}) dihapus permanen.",
                oldValues: $variant->only(['product_id', 'sku', 'variant_name', 'sell_price', 'is_active']),
            );
            $variant->forceDelete();

            return null;
        });
    }
}
