<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\VariantOutletStock;
use App\Support\ModeGate;
use App\Support\OutletFilter;
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
 * 045 Stage 5 — stok adalah saldo PER OUTLET: satu "item menipis" = satu pasangan (varian, outlet) yang punya saldo,
 * dibatasi pada outlet yang berlaku (OutletFilter). Satu outlet = angka yang sama seperti sebelumnya.
 *
 * Pembatasan yang disengaja: pasangan tanpa movement sebelum instan itu dianggap BELUM ada (tidak dihitung).
 */
class LowStockHistory
{
    /** Persis hasil `/stock/low`. */
    public static function current(): int
    {
        return self::currentQuery()->count();
    }

    /** Pasangan (varian aktif ber-ambang, outlet) yang saldonya ≤ ambang — satu definisi untuk /stock/low dan dashboard. */
    public static function currentQuery()
    {
        $q = VariantOutletStock::query()
            ->join('product_variants', 'product_variants.id', '=', 'variant_outlet_stock.variant_id')
            ->whereNull('product_variants.deleted_at')
            ->where('product_variants.data_mode', ModeGate::current())
            ->whereNotNull('product_variants.low_stock_alert')
            ->whereColumn('variant_outlet_stock.current_stock', '<=', 'product_variants.low_stock_alert')
            ->where('product_variants.is_active', 1);

        return OutletFilter::apply($q, 'variant_outlet_stock.outlet_id');
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
            ->when(true, fn ($q) => OutletFilter::apply($q, 'outlet_id'))
            ->where('created_at', '<', $earliest)
            ->selectRaw('variant_id, outlet_id, MAX(id) as last_id')
            ->groupBy('variant_id', 'outlet_id')
            ->get()
            ->each(function ($row) use (&$baseline) {
                $baseline[$row->variant_id.':'.$row->outlet_id] = (int) DB::table('stock_movements')->where('id', $row->last_id)->value('stock_after');
            });

        $movements = DB::table('stock_movements')
            ->where('data_mode', $mode)
            ->whereIn('variant_id', $thresholds->keys())
            ->when(true, fn ($q) => OutletFilter::apply($q, 'outlet_id'))
            ->where('created_at', '>=', $earliest)
            ->where('created_at', '<=', $latest)
            ->orderBy('created_at')->orderBy('id')
            ->get(['variant_id', 'outlet_id', 'stock_after', 'created_at']);

        $level = $baseline;           // variant_id => stok terakhir yang diketahui
        $result = array_fill(0, count($instants), 0);
        $cursor = 0;
        $count = $movements->count();

        foreach ($sorted as $point) {
            $limit = $point['t']->format('Y-m-d H:i:s');
            while ($cursor < $count && (string) $movements[$cursor]->created_at <= $limit) {
                $level[$movements[$cursor]->variant_id.':'.$movements[$cursor]->outlet_id] = (int) $movements[$cursor]->stock_after;
                $cursor++;
            }
            $low = 0;
            foreach ($level as $pair => $stock) {
                if ($stock <= (int) $thresholds[(int) explode(':', (string) $pair)[0]]) {
                    $low++;
                }
            }
            $result[$point['i']] = $low;
        }

        return $result;
    }
}
