<?php

namespace App\Services\StockSync;

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockService;
use App\Support\ModeGate;
use Illuminate\Support\Facades\DB;

/**
 * 051-stock-sync-from-transactions — PENERAP: satu-satunya bagian fitur ini yang menulis.
 *
 * - Semua koreksi dalam SATU transaksi database (semua atau tidak sama sekali): kegagalan di varian mana pun
 *   membatalkan seluruhnya.
 * - Penulisan HANYA lewat StockService::applyMovement() (jalur tulis stok yang sah): ia mengunci baris varian, menolak
 *   stok negatif, dan menulis log `stock_adjusted` di transaksi yang sama. `current_stock` tidak pernah ditulis langsung.
 * - `reference_type = stock_sync` + `reference_id` = id pergerakan acuan adalah penanda idempoten yang dibaca
 *   StockSyncPlanner; tanpanya koreksi ini sendiri akan menjadi "pembaruan terakhir" dan transaksi di antara
 *   pembaruan asli dan koreksi akan dikurangkan dua kali.
 * - Baris dengan selisih nol dilewati (applyMovement menolak qty nol); kekurangan pada varian yang stoknya sudah 0
 *   hanya tampil di laporan.
 */
class StockSyncApplier
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array{rows: list<array<string, mixed>>}  $plan
     * @return array{changed: int, units: int, rows: list<array<string, mixed>>}
     */
    public function apply(array $plan, User $by): array
    {
        return ModeGate::runAs(ModeGate::LIVE, fn () => DB::transaction(function () use ($plan, $by) {
            $changed = [];

            foreach ($plan['rows'] as $row) {
                if (! in_array($row['status'], StockSyncPlanner::WRITING_STATUSES, true) || $row['delta'] === 0) {
                    continue;
                }

                $variant = ProductVariant::findOrFail($row['variant_id']);

                // Pengaman kedua (yang utama: kode rencana di perintah): rencana lama tidak boleh menimpa stok yang
                // sudah berubah sejak dihitung.
                if ((int) $variant->current_stock !== $row['current_stock']) {
                    throw new \RuntimeException("Stok {$variant->sku} berubah sejak rencana dihitung; tinjau ulang.");
                }

                $this->stock->applyMovement(
                    variant: $variant,
                    type: 'adjustment',
                    qtyChange: $row['delta'],
                    referenceType: StockSyncPlanner::SYNC_REFERENCE,
                    referenceId: $row['baseline_id'],
                    reason: $this->reason($row),
                    userId: $by->id,
                );

                $changed[] = $row;
            }

            return [
                'changed' => count($changed),
                'units' => array_sum(array_map(fn ($r) => -$r['delta'], $changed)),
                'rows' => $changed,
            ];
        }));
    }

    /** @param  array<string, mixed>  $row */
    private function reason(array $row): string
    {
        $text = "Sinkronisasi stok dengan transaksi sebelum pembaruan stok #{$row['baseline_id']}: terjual {$row['sold']}, "
            ."serah-terima pre-order {$row['handed_over']}, retur {$row['returned']} (pengurang bersih {$row['taken']})";

        if ($row['shortfall'] > 0) {
            $text .= "; kekurangan {$row['shortfall']} unit (stok dibatasi ke 0)";
        }

        return $text;
    }
}
