<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA definisi "pendapatan pre-order yang diakui": porsi subtotal
 * yang sudah BENAR-BENAR terkumpul di `payments` (bukan cache
 * preorders.paid_amount), dikalikan ke line_total/qty/cost_price tiap
 * preorder_items.
 *
 * Dipindahkan apa adanya dari ReportController (fitur 010) pada fitur 076
 * supaya laporan DAN settlement seller memakai rumus yang identik — jangan
 * menambah rumus kedua "POS vs pre-order" (aturan fitur 033). SQL-nya tidak
 * berubah satu karakter pun; ReportController kini hanya mendelegasikan.
 *
 * Query tangan-tulis memang melewati global scope Eloquent, jadi data_mode
 * difilter EKSPLISIT di sini (003-seed-demo-live).
 */
final class PreorderRecognizedRevenue
{
    /**
     * Faktor pengali per baris preorder_items; dievaluasi di SQL lewat alias
     * `pc.collected` dari query() di bawah (bukan loop PHP per pre-order).
     */
    public const FRACTION_EXPR = 'CASE WHEN preorders.subtotal > 0 THEN COALESCE(pc.collected, 0) / preorders.subtotal ELSE 0 END';

    /**
     * Dasar query (FROM + JOIN + filter, TANPA select). Subquery `pc`
     * menjumlahkan payments.amount per preorder, mengecualikan
     * verification='rejected', dihitung SEKALI lewat leftJoinSub (bukan N+1).
     * Pre-order 'cancelled' dikeluarkan sepenuhnya lewat where(), bukan
     * cuma fraction = 0.
     */
    public static function query(?int $eventId = null, ?CarbonInterface $dateFrom = null, ?CarbonInterface $dateTo = null): Builder
    {
        $collected = DB::table('payments')
            ->select('preorder_id', DB::raw('SUM(amount) as collected'))
            ->whereNotNull('preorder_id')
            ->where('verification', '!=', 'rejected')
            ->where('data_mode', ModeGate::current())
            ->groupBy('preorder_id');

        return DB::table('preorder_items')
            ->join('preorders', 'preorders.id', '=', 'preorder_items.preorder_id')
            ->join('product_variants', 'product_variants.id', '=', 'preorder_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->join('artists', 'artists.id', '=', 'preorder_items.artist_id')
            ->join('events', 'events.id', '=', 'preorders.event_id')
            ->leftJoinSub($collected, 'pc', 'pc.preorder_id', '=', 'preorders.id')
            ->where('preorders.status', '!=', 'cancelled')
            ->where('preorder_items.data_mode', ModeGate::current())
            ->when($eventId !== null, fn ($q) => $q->where('preorders.event_id', $eventId))
            ->when($dateFrom !== null, fn ($q) => $q->whereDate('preorders.created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($q) => $q->whereDate('preorders.created_at', '<=', $dateTo));
    }
}
