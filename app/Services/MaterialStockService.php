<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\MaterialStockMovement;
use App\Models\Outlet;
use App\Support\OutletStockSync;
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
 * 045 Stage 5 — saldo PER OUTLET (`material_outlet_stock`); `$outletId` wajib. Tipe: 'purchase' (penerimaan PO di
 * outlet PO), 'adjustment' (koreksi manual per outlet), dan 'transfer_*' (Tahap 6). Total tersimpan
 * `materials.current_stock` = Σ saldo outlet, ditulis di transaksi yang sama.
 */
class MaterialStockService
{
    /**
     * @throws ValidationException bila hasil qty_change membuat stok negatif
     */
    public function applyMovement(
        Material $material,
        int $outletId,
        string $type,
        float $qtyChange,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $userId = null,
    ): MaterialStockMovement {
        if ($qtyChange == 0) {
            throw new \InvalidArgumentException('qty_change tidak boleh nol.');
        }

        return DB::transaction(function () use ($material, $outletId, $type, $qtyChange, $referenceType, $referenceId, $userId) {
            return OutletStockSync::internally(function () use ($material, $outletId, $type, $qtyChange, $referenceType, $referenceId, $userId) {
                $locked = Material::where('id', $material->id)->lockForUpdate()->firstOrFail();

                $balance = MaterialOutletStock::query()->where('material_id', $locked->id)->where('outlet_id', $outletId)->lockForUpdate()->first();
                if ($balance === null) {
                    MaterialOutletStock::query()->insertOrIgnore(['material_id' => $locked->id, 'outlet_id' => $outletId, 'current_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    $balance = MaterialOutletStock::query()->where('material_id', $locked->id)->where('outlet_id', $outletId)->lockForUpdate()->firstOrFail();
                }

                $stockBefore = (float) $balance->current_stock;
                $stockAfter = $stockBefore + $qtyChange;

                if ($stockAfter < -0.0005) {
                    $outletName = Outlet::withTrashed()->whereKey($outletId)->value('name');
                    throw ValidationException::withMessages([
                        'qty_change' => __('vendors_materials.insufficient_material_stock', ['material' => $locked->name, 'available' => $stockBefore]).' ('.$outletName.')',
                    ]);
                }
                $stockAfter = max(0.0, $stockAfter);

                $movement = MaterialStockMovement::create([
                    'material_id' => $locked->id,
                    'outlet_id' => $outletId,
                    'type' => $type,
                    'qty_change' => $qtyChange,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);

                $balance->update(['current_stock' => $stockAfter]);
                $locked->update(['current_stock' => (float) $locked->current_stock + $qtyChange]);

                return $movement;
            });
        });
    }
}
