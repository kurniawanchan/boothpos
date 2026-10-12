<?php

namespace App\Services;

use App\Support\ModeGate;
use Illuminate\Support\Facades\DB;

/**
 * Rekap penjualan per outlet untuk satu PERIODE tanggal bisnis, dihitung LANGSUNG dari order_items
 * setiap dipanggil (sumber kebenarannya selalu order_items).
 *
 * 046-hide-events-preorders — event dihapus dari produk, jadi rekap tidak lagi per event dan tidak
 * lagi ditulis ke `outlet_settlements` (tidak ada lagi "tutup event" yang menyimpan snapshot).
 * Tabel itu dibiarkan apa adanya sebagai riwayat. Aturan yang diwarisi dari 040/045: hanya order
 * `completed` (POS), tanpa pre-order, dan dikelompokkan per outlet PELAPORAN
 * (OutletFilter::reportOutletSql — tempat penjualan; order lama tetap penjual lamanya).
 */
class SettlementService
{
    /**
     * Penjualan POS per outlet pada periode [$from, $to] (tanggal bisnis, Y-m-d; null = tanpa batas).
     * Query tangan-tulis → filter data_mode EKSPLISIT (tidak mewarisi global scope Eloquent).
     *
     * @return \Illuminate\Support\Collection<int, array{sales: float, units: float}>
     */
    public function posSalesForPeriod(?string $from, ?string $to): \Illuminate\Support\Collection
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat ReportController::sales()
            ->when($from, fn ($q) => $q->where('orders.business_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', $to))
            // 045 Stage 7 — dikelompokkan per outlet PELAPORAN (tempat penjualan; order lama tetap penjual lamanya).
            ->selectRaw(\App\Support\OutletFilter::reportOutletSql().' as report_outlet_id, SUM(order_items.line_total) as total_sales, SUM(order_items.qty) as total_units')
            ->groupBy(DB::raw(\App\Support\OutletFilter::reportOutletSql()))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->report_outlet_id => [
                'sales' => (float) $row->total_sales,
                'units' => (float) $row->total_units,
            ]]);
    }
}
