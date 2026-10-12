<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\OutletItemSetting;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * 045 Stage 3 — SATU pengelola relasi outlet × produk (`outlet_item_settings`).
 *
 * Setiap pasangan (outlet, produk) pada mode data yang sama punya satu baris. Baris baru dibuat saat produk
 * atau outlet dibuat (kait di AppServiceProvider, jadi semua jalur — controller, impor Excel, seeder — tercakup),
 * bawaannya `is_carried = true`. Pasangan tanpa baris dianggap DIBAWA (bawaan aman: tidak ada produk yang
 * hilang dari layar bila baris belum sempat dibuat).
 */
class OutletMenuService
{
    public function materializeForProduct(Product $product): void
    {
        DB::insert(
            'INSERT IGNORE INTO outlet_item_settings (outlet_id, product_id, is_carried, data_mode, created_at, updated_at)
             SELECT id, ?, 1, ?, NOW(), NOW() FROM outlets WHERE deleted_at IS NULL AND data_mode = ?',
            [$product->id, $product->data_mode, $product->data_mode],
        );
    }

    public function materializeForOutlet(Outlet $outlet): void
    {
        DB::insert(
            'INSERT IGNORE INTO outlet_item_settings (outlet_id, product_id, is_carried, data_mode, created_at, updated_at)
             SELECT ?, id, 1, ?, NOW(), NOW() FROM products WHERE deleted_at IS NULL AND data_mode = ?',
            [$outlet->id, $outlet->data_mode, $outlet->data_mode],
        );
    }

    public function isCarried(int $productId, int $outletId): bool
    {
        $row = OutletItemSetting::query()->where('product_id', $productId)->where('outlet_id', $outletId)->value('is_carried');

        return $row === null ? true : (bool) $row;
    }

    /**
     * Menetapkan outlet mana yang membawa produk ini: yang ada di $carriedOutletIds dibawa, outlet lain di mode
     * yang sama tidak. Semua atau tidak sama sekali (satu transaksi).
     *
     * @param  list<int>  $carriedOutletIds
     * @param  list<int>|null  $managedOutletIds  hanya outlet ini yang diubah (outlet lain tetap); null = semua outlet
     */
    public function setCarried(Product $product, array $carriedOutletIds, ?array $managedOutletIds = null): void
    {
        DB::transaction(function () use ($product, $carriedOutletIds, $managedOutletIds) {
            $this->materializeForProduct($product);
            $query = OutletItemSetting::query()->where('product_id', $product->id)
                ->when($managedOutletIds !== null, fn ($q) => $q->whereIn('outlet_id', $managedOutletIds ?: [0]));
            if ($carriedOutletIds === []) {
                $query->update(['is_carried' => false]);
            } else {
                $query->update(['is_carried' => DB::raw('outlet_id IN ('.implode(',', array_map('intval', $carriedOutletIds)).')')]);
            }
        });
    }

    /** @return list<int> id outlet yang membawa produk ini (pasangan tanpa baris dihitung dibawa). */
    public function carriedOutletIds(Product $product): array
    {
        $off = OutletItemSetting::query()->where('product_id', $product->id)->where('is_carried', false)->pluck('outlet_id')->all();

        return Outlet::query()->whereNotIn('id', $off)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
