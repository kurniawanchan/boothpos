<?php

namespace App\Services;

use App\Support\ModeGate;
use App\Support\OutletFilter;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 042-dashboard-revamp — SATU implementasi "penjualan POS": halaman Sales (lewat
 * `ReportController::sales()`) dan Dashboard membaca angka yang sama dari sini, sehingga keduanya
 * tidak bisa berbeda (Konstitusi I; spec SC-001).
 *
 * Definisi yang DIWARISI dari laporan penjualan sebelum fitur ini (dikunci oleh
 * SalesTotalsCharacterizationTest, bukan diperbaiki di sini):
 *  - hanya order `completed` (open & voided tidak ikut);
 *  - net = SUM(order_items.line_total) — diskon tingkat-order TIDAK dikurangkan (keputusan O3);
 *  - bruto = net + diskon item; jumlah order = COUNT(DISTINCT orders.id) yang punya baris item.
 *
 * Perubahan 042: tanggal dihitung dari `orders.business_date` (hari bisnis zona waktu toko), bukan
 * `DATE(orders.created_at)` UTC. Query hand-rolled `DB::table()` melewati global scope Eloquent,
 * jadi `data_mode` difilter eksplisit di sini (lihat CLAUDE.md, bagian DEMO/LIVE).
 */
class SalesFigures
{
    /**
     * Basis query order_items ⨝ orders completed — dasar yang sama untuk totalan dan semua
     * pengelompokan di laporan penjualan. Join dipertahankan persis agar angkanya identik dengan
     * perilaku sebelumnya.
     *
     * @param  array{date_from?: string|null, date_to?: string|null}  $filters  tanggal = tanggal BISNIS (Y-m-d)
     */
    public static function posBase(array $filters = []): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->tap(fn ($q) => OutletFilter::joinReportOutlet($q)) // 045 Stage 7: outlet PELAPORAN (lihat OutletFilter::reportOutletSql)
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current())
            ->when(true, fn ($q) => OutletFilter::applyReport($q))
            ->when(! empty($filters['date_from']), fn ($q) => $q->where('orders.business_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->where('orders.business_date', '<=', $filters['date_to']));
    }

    /**
     * Versi RINGAN dari `posBase()` untuk angka Dashboard (totalan, deret per hari/jam): hanya order_items ⨝ orders.
     * Empat join lainnya (varian, produk, kategori, outlet) tidak memfilter apa pun — semua
     * kuncinya `NOT NULL ... RESTRICT`, jadi tiap baris item SELALU punya pasangan — dan hanya dibutuhkan
     * laporan yang mengelompokkan menurut nama (produk/kategori/outlet). Hasilnya identik dengan
     * `posBase()` (dijaga DashboardSummaryTest::the_headline_sales_equal_the_sales_page...), tetapi jauh lebih
     * murah: join tambahan itu pernah membuat ringkasan "hari ini" lebih dari 6 detik dengan 1.000 order.
     */
    public static function leanBase(array $filters = []): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current())
            ->when(true, fn ($q) => OutletFilter::apply($q, 'orders.outlet_id'))
            ->when(! empty($filters['date_from']), fn ($q) => $q->where('orders.business_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->where('orders.business_date', '<=', $filters['date_to']));
    }

    /** Totalan POS dari sebuah basis (`posBase()` yang boleh sudah dipersempit pemanggil). */
    public static function totals(Builder $base): object
    {
        $totals = (clone $base)->selectRaw('
            COUNT(DISTINCT orders.id) as order_count,
            SUM(order_items.qty) as unit_count,
            SUM(order_items.line_total + order_items.discount_amount) as gross_sales,
            SUM(order_items.discount_amount) as discount_total,
            SUM(order_items.line_total) as net_sales
        ')->first();

        return $totals ?? (object) ['order_count' => 0, 'unit_count' => 0, 'gross_sales' => 0, 'discount_total' => 0, 'net_sales' => 0];
    }

    /**
     * Penjualan bersih per tanggal bisnis di rentang [from, to] → ['Y-m-d' => '123.00'].
     * Hari tanpa penjualan TIDAK ada di hasil; pemanggil mengisi nol.
     *
     * @return array<string, string>
     */
    public static function netByDay(string $from, string $to): array
    {
        return self::leanBase(['date_from' => $from, 'date_to' => $to])
            ->selectRaw('orders.business_date as day, SUM(order_items.line_total) as net')
            ->groupBy('orders.business_date')
            ->pluck('net', 'day')
            ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
            ->all();
    }

    /**
     * Order SEMUA status (open, completed, voided) per tanggal bisnis → ['Y-m-d' => int].
     * Dipakai kartu "Total order" (label desain: "Semua status").
     *
     * @return array<string, int>
     */
    public static function orderCountByDay(string $from, string $to): array
    {
        return DB::table('orders')
            ->where('orders.data_mode', ModeGate::current())
            ->when(true, fn ($q) => OutletFilter::apply($q, 'orders.outlet_id'))
            ->whereBetween('orders.business_date', [$from, $to])
            ->selectRaw('orders.business_date as day, COUNT(*) as n')
            ->groupBy('orders.business_date')
            ->pluck('n', 'day')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Penjualan bersih per ORDER selesai dalam jendela instan [start, end) — dasar deret per jam.
     * Dipanggil hanya untuk satu hari bisnis (ratusan–ribuan baris); pengelompokan per jam dilakukan
     * di PHP karena konversi zona waktu di SQL butuh tabel zona MySQL yang tidak dijamin ada.
     *
     * @return list<array{at: string, net: float}>  `at` = completed_at (UTC, 'Y-m-d H:i:s')
     */
    public static function completedNetBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        return self::leanBase()
            ->where('orders.completed_at', '>=', $start->utc()->format('Y-m-d H:i:s'))
            ->where('orders.completed_at', '<', $end->utc()->format('Y-m-d H:i:s'))
            ->selectRaw('orders.id, orders.completed_at as at, SUM(order_items.line_total) as net')
            ->groupBy('orders.id', 'orders.completed_at')
            ->get()
            ->map(fn ($r) => ['at' => (string) $r->at, 'net' => (float) $r->net])
            ->all();
    }

    /**
     * Jumlah order SEMUA status yang dibuat/diselesaikan di tiap jam pada jendela [start, end), dengan
     * pengelompokan di PHP (alasan sama seperti completedNetBetween).
     *
     * @return list<string>  instan (UTC, 'Y-m-d H:i:s') tiap order; `completed_at` bila ada, else `created_at`
     */
    public static function orderInstantsBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        return DB::table('orders')
            ->where('orders.data_mode', ModeGate::current())
            ->when(true, fn ($q) => OutletFilter::apply($q, 'orders.outlet_id'))
            ->whereRaw('COALESCE(orders.completed_at, orders.created_at) >= ?', [$start->utc()->format('Y-m-d H:i:s')])
            ->whereRaw('COALESCE(orders.completed_at, orders.created_at) < ?', [$end->utc()->format('Y-m-d H:i:s')])
            ->selectRaw('COALESCE(orders.completed_at, orders.created_at) as at')
            ->pluck('at')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
