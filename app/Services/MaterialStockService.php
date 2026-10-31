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
 *
 * 087 — tipe 'sale', 'return', 'waste' dari pilihan modifier (ditulis HANYA lewat ModifierMaterialConsumption).
 * 'sale' dan 'waste' BOLEH membuat saldo minus (keputusan pemilik: penjualan tidak pernah ditolak karena bahan);
 * CHECK ≥ 0 di database sudah dilepas, jadi penjaga untuk tipe lain kini hanya ada di sini (research D6).
 */
class MaterialStockService
{
    /** Tipe yang boleh menghasilkan saldo minus; tipe lain tetap ditolak bila hasilnya < 0. */
    public const NEGATIVE_ALLOWED = ['sale', 'waste'];

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
        ?string $reason = null,
    ): MaterialStockMovement {
        if ($qtyChange == 0) {
            throw new \InvalidArgumentException('qty_change tidak boleh nol.');
        }

        return DB::transaction(function () use ($material, $outletId, $type, $qtyChange, $referenceType, $referenceId, $userId, $reason) {
            return OutletStockSync::internally(function () use ($material, $outletId, $type, $qtyChange, $referenceType, $referenceId, $userId, $reason) {
                $locked = Material::where('id', $material->id)->lockForUpdate()->firstOrFail();

                $balance = MaterialOutletStock::query()->where('material_id', $locked->id)->where('outlet_id', $outletId)->lockForUpdate()->first();
                if ($balance === null) {
                    MaterialOutletStock::query()->insertOrIgnore(['material_id' => $locked->id, 'outlet_id' => $outletId, 'current_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    $balance = MaterialOutletStock::query()->where('material_id', $locked->id)->where('outlet_id', $outletId)->lockForUpdate()->firstOrFail();
                }

                $stockBefore = (float) $balance->current_stock;
                $stockAfter = $stockBefore + $qtyChange;

                // Penambahan (beli, koreksi +, transfer masuk) SELALU boleh, juga bila hasilnya masih minus
                // (FR-014a: menambah dari angka minus). Yang dijaga hanya pengurangan selain sale/waste.
                if ($qtyChange < 0 && ! in_array($type, self::NEGATIVE_ALLOWED, true)) {
                    if ($stockAfter < -0.0005) {
                        $outletName = Outlet::withTrashed()->whereKey($outletId)->value('name');
                        throw ValidationException::withMessages([
                            'qty_change' => __('vendors_materials.insufficient_material_stock', ['material' => $locked->name, 'available' => $stockBefore]).' ('.$outletName.')',
                        ]);
                    }
                    $stockAfter = max(0.0, $stockAfter);
                }
                // 087: untuk 'sale'/'waste' saldo minus disimpan apa adanya — JANGAN dijepit ke 0, karena
                // penjepitan diam-diam akan menghapus kekurangan yang justru harus terlihat untuk stok opname.

                $movement = MaterialStockMovement::create([
                    'material_id' => $locked->id,
                    'outlet_id' => $outletId,
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

                $balance->update(['current_stock' => $stockAfter]);
                $locked->update(['current_stock' => (float) $locked->current_stock + $qtyChange]);

                return $movement;
            });
        });
    }
}
