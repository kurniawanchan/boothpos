<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 006-purchase-order-and-ops (US1) — satu-satunya jalur sah untuk
 * mengubah stok bahan baku (Material), mencerminkan StockService milik
 * ProductVariant persis (row-lock + riwayat append-only dalam satu
 * transaksi) tapi SENGAJA jalur terpisah, bukan perluasan StockService —
 * Material bukan ProductVariant, keduanya concern yang berbeda meski
 * polanya sama. Lihat research.md R4.
 *
 * Saat ini hanya dipanggil dengan type='purchase' saat PurchaseOrder
 * ditransisikan ke status Received (PurchaseOrderService). Tipe lain
 * (mis. 'adjustment' untuk koreksi manual) di luar cakupan fitur ini.
 */
class MaterialStockService
{
    /**
     * @throws ValidationException bila hasil qty_change membuat stok negatif
     */
    public function applyMovement(
        Material $material,
        string $type,
        float $qtyChange,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $userId = null,
    ): MaterialStockMovement {
        if ($qtyChange == 0) {
            throw new \InvalidArgumentException('qty_change tidak boleh nol.');
        }

        return DB::transaction(function () use ($material, $type, $qtyChange, $referenceType, $referenceId, $userId) {
            $locked = Material::where('id', $material->id)->lockForUpdate()->firstOrFail();

            $stockBefore = (float) $locked->current_stock;
            $stockAfter = $stockBefore + $qtyChange;

            if ($stockAfter < 0) {
                throw ValidationException::withMessages([
                    'qty_change' => __('vendors_materials.insufficient_material_stock', ['material' => $locked->name, 'available' => $stockBefore]),
                ]);
            }

            $movement = MaterialStockMovement::create([
                'material_id' => $locked->id,
                'type' => $type,
                'qty_change' => $qtyChange,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            $locked->update(['current_stock' => $stockAfter]);

            return $movement;
        });
    }
}
