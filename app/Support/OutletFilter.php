<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * 045 Stage 2 — padanan OutletScope untuk query tangan-tulis (`DB::table()`), yang melewati
 * global scope Eloquent (jebakan yang sama dengan DataModeScope; lihat CLAUDE.md).
 *
 * Satu fungsi, dipakai SETIAP query mentah atas tabel operasional (orders, payments, sesi,
 * pre-order). Aturannya identik dengan OutletScope: hanya menyaring di dalam permintaan ber-login,
 * memakai OutletContext::effectiveIds() (pilihan X-Outlet-Id, selain itu semua outlet yang boleh).
 */
final class OutletFilter
{
    /**
     * @template T of Builder|EloquentBuilder
     *
     * @param  T  $query
     * @return T
     */
    public static function apply($query, string $column)
    {
        $context = app(OutletContext::class);

        if ($context->user() === null) {
            return $query;
        }

        return $query->whereIn($column, $context->effectiveIds());
    }

    /**
     * 045 Stage 7 — outlet PELAPORAN sebuah item order: outlet TEMPAT penjualan terjadi (`orders.outlet_id`); untuk order
     * LAMA (`outlet_backfilled`, dibuat sebelum ada outlet penjualan) tetap outlet ASAL item (penjual dulu) supaya angka
     * lampau tidak bergeser (Q2 = B, FR-026). Satu-satunya definisi; dipakai laporan, rekap, dan snapshot settlement.
     */
    public static function reportOutletSql(string $orders = 'orders', string $items = 'order_items'): string
    {
        return "(CASE WHEN {$orders}.outlet_backfilled = 1 THEN {$items}.home_outlet_id ELSE {$orders}.outlet_id END)";
    }

    /** Join `outlets` pada outlet PELAPORAN (lihat reportOutletSql). */
    public static function joinReportOutlet($query, string $orders = 'orders', string $items = 'order_items')
    {
        return $query->join('outlets', fn ($j) => $j->whereRaw('outlets.id = '.self::reportOutletSql($orders, $items)));
    }

    /** Menyaring pada outlet PELAPORAN (bukan outlet order) — untuk laporan per outlet yang mengelompokkan per item. */
    public static function applyReport($query, string $orders = 'orders', string $items = 'order_items')
    {
        $context = app(OutletContext::class);

        return $context->user() === null ? $query : $query->whereRaw(self::reportOutletSql($orders, $items).' in ('.implode(',', array_map('intval', $context->effectiveIds() ?: [0])).')');
    }

    /** Potongan kunci cache untuk figur yang bergantung pada outlet (Constitution V: setiap masukan yang mengubah isi). */
    public static function cacheKey(): string
    {
        $context = app(OutletContext::class);

        return $context->user() === null ? 'all' : implode('-', $context->effectiveIds());
    }
}
