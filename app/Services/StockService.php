<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur sah untuk mengubah stok. Dipakai oleh: penyesuaian
 * manual, transaksi penjualan, dan alur pre-order (arrived/handed_over).
 *
 * Dijadikan service terpisah (bukan logic tersebar di tiap controller)
 * karena dipakai oleh lebih dari satu modul — ambang batas DRY yang wajar
 * untuk keluar dari filosofi "controller tipis" pada modul-modul
 * sebelumnya.
 */
class StockService
{
    /**
     * @throws ValidationException bila hasil qty_change membuat stok negatif
     */
    public function applyMovement(
        ProductVariant $variant,
        string $type,
        int $qtyChange,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?int $userId = null,
    ): StockMovement {
        if ($qtyChange === 0) {
            throw new \InvalidArgumentException('qty_change tidak boleh nol.');
        }

        return DB::transaction(function () use ($variant, $type, $qtyChange, $referenceType, $referenceId, $reason, $userId) {
            // Row lock — mencegah dua transaksi bersamaan membaca
            // current_stock yang sama lalu sama-sama menuliskan hasil yang
            // salah (race condition klasik saat antrean kasir padat).
            $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

            $stockBefore = $locked->current_stock;
            $stockAfter = $stockBefore + $qtyChange;

            if ($stockAfter < 0) {
                throw ValidationException::withMessages([
                    'qty_change' => "Stok tidak mencukupi untuk varian {$locked->sku}. Tersedia: {$stockBefore}.",
                ]);
            }

            $movement = StockMovement::create([
                'variant_id' => $locked->id,
                'type' => $type,
                'qty_change' => $qtyChange,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'user_id' => $userId,
                'created_at' => now(),
            ]);

            $locked->update(['current_stock' => $stockAfter]);

            return $movement;
        });
    }
}
