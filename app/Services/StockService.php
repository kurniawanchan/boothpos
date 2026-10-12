<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\VariantOutletStock;
use App\Support\OutletStockSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur sah untuk mengubah stok. Dipakai oleh: penyesuaian
 * manual, transaksi penjualan, dan transfer antar-outlet.
 *
 * 045 Stage 5 — stok adalah SALDO PER OUTLET (`variant_outlet_stock`): setiap pergerakan WAJIB menyebut outlet
 * (argumen `$outletId` tanpa nilai bawaan — memanggil tanpa outlet adalah galat pemrograman, dijaga tes pemindai
 * sumber). Dalam SATU transaksi: kunci varian, kunci saldo (varian, outlet), tulis riwayat (stock_before/after = saldo
 * outlet itu), perbarui saldo DAN total tersimpan `product_variants.current_stock` (Σ saldo outlet).
 *
 * Dijadikan service terpisah (bukan logic tersebar di tiap controller)
 * karena dipakai oleh lebih dari satu modul — ambang batas DRY yang wajar
 * untuk keluar dari filosofi "controller tipis" pada modul-modul
 * sebelumnya.
 */
class StockService
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * 028 — hidangan made-to-order (`track_stock = false`) tidak punya stok
     * fisik yang bisa dihitung, jadi tidak ada yang dicek/dikurangi/
     * dikembalikan dan tidak ada baris stock_movements. Untuk varian yang
     * dilacak, perilakunya IDENTIK dengan applyMovement() — method ini hanya
     * mendelegasikan, bukan jalur tulis kedua (Constitution I: satu-satunya
     * jalur sah mengubah stok tetap applyMovement()).
     *
     * Dipakai oleh kasir ritel (OrderService) DAN alur tab restoran, supaya
     * satu varian berperilaku sama di kedua layar.
     *
     * @return StockMovement|null null bila varian tidak dilacak
     * @throws ValidationException bila hasil qty_change membuat stok negatif
     */
    public function applyMovementIfTracked(
        ProductVariant $variant,
        int $outletId,
        string $type,
        int $qtyChange,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?int $userId = null,
    ): ?StockMovement {
        if (! $variant->track_stock) {
            return null;
        }

        return $this->applyMovement($variant, $outletId, $type, $qtyChange, $referenceType, $referenceId, $reason, $userId);
    }

    /**
     * @throws ValidationException bila hasil qty_change membuat stok negatif
     */
    public function applyMovement(
        ProductVariant $variant,
        int $outletId,
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

        return DB::transaction(function () use ($variant, $outletId, $type, $qtyChange, $referenceType, $referenceId, $reason, $userId) {
            return OutletStockSync::internally(function () use ($variant, $outletId, $type, $qtyChange, $referenceType, $referenceId, $reason, $userId) {
                // Row lock varian DULU (urutan kunci tetap: varian → saldo) — mencegah dua transaksi bersamaan membaca
                // angka yang sama lalu menuliskan hasil yang salah (race condition klasik saat antrean kasir padat).
                $locked = ProductVariant::where('id', $variant->id)->lockForUpdate()->firstOrFail();

                $balance = VariantOutletStock::query()->where('variant_id', $locked->id)->where('outlet_id', $outletId)->lockForUpdate()->first();
                if ($balance === null) {
                    // Saldo outlet ini belum pernah ada (varian baru / outlet baru): mulai dari nol, lalu kunci barisnya.
                    VariantOutletStock::query()->insertOrIgnore(['variant_id' => $locked->id, 'outlet_id' => $outletId, 'current_stock' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    $balance = VariantOutletStock::query()->where('variant_id', $locked->id)->where('outlet_id', $outletId)->lockForUpdate()->firstOrFail();
                }

                $stockBefore = (int) $balance->current_stock;
                $stockAfter = $stockBefore + $qtyChange;

                if ($stockAfter < 0) {
                    $outletName = Outlet::withTrashed()->whereKey($outletId)->value('name');
                    throw ValidationException::withMessages([
                        'qty_change' => __('master_data.insufficient_stock', ['sku' => $locked->sku, 'available' => $stockBefore]).' ('.$outletName.')',
                    ]);
                }

                $movement = StockMovement::create([
                    'variant_id' => $locked->id,
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
                $locked->update(['current_stock' => $locked->current_stock + $qtyChange]);

            // F13.4 — hanya penyesuaian MANUAL yang dianggap "tindakan
            // sensitif" per PRD 7.13, bukan setiap pergerakan stok (jual/
            // beli juga lewat method ini tapi bukan sasaran F13.4).
            // Ditulis di sini, bukan di StockController, supaya berlaku
            // untuk SEMUA pemanggil applyMovement() bertipe 'adjustment' di
            // masa depan juga — dan tetap di dalam transaksi yang sama
            // dengan mutasi stoknya sendiri (atomik, ikut rollback bersama).
            if ($type === 'adjustment') {
                $this->activityLogger->log(
                    userId: $userId,
                    action: 'stock_adjusted',
                    entityType: 'ProductVariant',
                    entityId: $locked->id,
                    description: "Penyesuaian stok {$locked->sku} sebanyak {$qtyChange} ({$stockBefore} -> {$stockAfter}).".($reason ? " Alasan: {$reason}." : ''),
                    oldValues: ['current_stock' => $stockBefore],
                    newValues: ['current_stock' => $stockAfter],
                );
            }

            return $movement;
            });
        });
    }
}
