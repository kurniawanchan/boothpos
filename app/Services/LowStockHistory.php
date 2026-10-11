<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Support\ModeGate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * 042-dashboard-revamp — jumlah item stok menipis SEKARANG dan pada instan-instan lampau.
 *
 * Definisi "menipis" sama persis dengan `GET /stock/low` (varian aktif dengan `low_stock_alert`
 * terisi dan `current_stock <= low_stock_alert`). Untuk "kemarin pada jam yang sama" dan tren,
 * stok lampau dibaca dari buku besar `stock_movements.stock_after` (movement terakhir sebelum
 * instan itu) — tanpa tabel riwayat baru dan tanpa penjadwal (research.md D8).
 *
 * Pembatasan yang disengaja: varian tanpa movement sebelum instan itu dianggap BELUM ada (tidak
 * dihitung). Stok per outlet belum ada (fase 3), jadi angkanya selalu seluruh organisasi.
 */
class LowStockHistory
{
    /** Persis hasil `/stock/low`. */
    public static function current(): int
    {
        return ProductVariant::query()
            ->whereNotNull('low_stock_alert')
            ->whereColumn('current_stock', '<=', 'low_stock_alert')
            ->where('is_active', 1)
            ->count();
    }

    /**
     * @param  list<CarbonInterface>  $instants  urutan bebas
     * @return list<int>  jumlah menipis pada tiap instan, urutan sama dengan input
     */
    public static function at(array $instants): array
    {
        if ($instants === []) {
            return [];
        }

        $thresholds = ProductVariant::query()
            ->whereNotNull('low_stock_alert')
            ->where('is_active', 1)
            ->pluck('low_stock_alert', 'id');

        if ($thresholds->isEmpty()) {
            return array_fill(0, count($instants), 0);
        }

        $sorted = collect($instants)->map(fn ($t, $i) => ['t' => $t->clone()->utc(), 'i' => $i])->sortBy(fn ($x) => $x['t']->getTimestamp())->values();
        $earliest = $sorted->first()['t']->format('Y-m-d H:i:s');
        $latest = $sorted->last()['t']->format('Y-m-d H:i:s');
        $mode = ModeGate::current();

        // Stok awal: movement terakhir SEBELUM instan paling awal, per varian.
        $baseline = [];
        DB::table('stock_movements')
            ->where('data_mode', $mode)
            ->whereIn('variant_id', $thresholds->keys())
            ->where('created_at', '<', $earliest)
            ->selectRaw('variant_id, MAX(id) as last_id')
            ->groupBy('variant_id')
            ->get()
            ->each(function ($row) use (&$baseline) {
                $baseline[$row->variant_id] = (int) DB::table('stock_movements')->where('id', $row->last_id)->value('stock_after');
            });

        $movements = DB::table('stock_movements')
            ->where('data_mode', $mode)
            ->whereIn('variant_id', $thresholds->keys())
            ->where('created_at', '>=', $earliest)
            ->where('created_at', '<=', $latest)
            ->orderBy('created_at')->orderBy('id')
            ->get(['variant_id', 'stock_after', 'created_at']);

        $level = $baseline;           // variant_id => stok terakhir yang diketahui
        $result = array_fill(0, count($instants), 0);
        $cursor = 0;
        $count = $movements->count();

        foreach ($sorted as $point) {
            $limit = $point['t']->format('Y-m-d H:i:s');
            while ($cursor < $count && (string) $movements[$cursor]->created_at <= $limit) {
                $level[$movements[$cursor]->variant_id] = (int) $movements[$cursor]->stock_after;
                $cursor++;
            }
            $low = 0;
            foreach ($level as $variantId => $stock) {
                if ($stock <= (int) $thresholds[$variantId]) {
                    $low++;
                }
            }
            $result[$point['i']] = $low;
        }

        return $result;
    }
}
