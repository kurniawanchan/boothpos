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

    /**
     * Totalan POS dari sebuah basis (`posBase()` yang boleh sudah dipersempit pemanggil).
     *
     * 048 / S4 — `tax_total` dan `grand_total` ditambahkan di sini. `net_sales` TETAP tanpa diskon tingkat-order
     * (keputusan O3, dikunci SalesTotalsCharacterizationTest). `grand_total` = net_sales − order_discount_total +
     * tax_total, sama dengan total struk; diskon tingkat-order tampil sebagai baris tersendiri.
     */
    public static function totals(Builder $base): object
    {
        $totals = (clone $base)->selectRaw('
            COUNT(DISTINCT orders.id) as order_count,
            SUM(order_items.qty) as unit_count,
            SUM(order_items.line_total + order_items.discount_amount) as gross_sales,
            SUM(order_items.discount_amount) as discount_total,
            SUM(order_items.line_total) as net_sales
        ')->first();

        $totals ??= (object) ['order_count' => 0, 'unit_count' => 0, 'gross_sales' => 0, 'discount_total' => 0, 'net_sales' => 0];

        $orderLevel = self::orderLevelSums($base);
        $totals->order_discount_total = $orderLevel->discount;
        $totals->tax_total = $orderLevel->tax;
        $totals->grand_total = self::grandTotal((float) ($totals->net_sales ?? 0), $orderLevel->discount, $orderLevel->tax);

        return $totals;
    }

    /**
     * Diskon dan pajak TINGKAT ORDER untuk basis yang sama, dihitung dari order yang DISTINCT. Basis item
     * menyalin kolom order ke setiap itemnya, jadi SUM langsung dari basis itu akan melipatgandakan pajak
     * (order 3 item = pajak 3 kali).
     */
    public static function orderLevelSums(Builder $base): object
    {
        $orders = (clone $base)->select('orders.id', 'orders.discount_amount', 'orders.tax_amount')->distinct();

        $row = DB::query()->fromSub($orders, 'o')
            ->selectRaw('COALESCE(SUM(o.discount_amount), 0) as discount, COALESCE(SUM(o.tax_amount), 0) as tax')
            ->first();

        return (object) ['discount' => (float) ($row->discount ?? 0), 'tax' => (float) ($row->tax ?? 0)];
    }

    /**
     * 048 / S4 — diskon dan pajak tingkat-order BERSIH (penjualan − refund) pada rentang TANGGAL BISNIS [from, to],
     * untuk kartu Dashboard periode. Refund dikurangkan pada tanggal refund, sama dengan netByDay().
     */
    public static function orderAdjustmentsBetween(string $from, string $to): object
    {
        $sales = self::orderLevelSums(self::leanBase(['date_from' => $from, 'date_to' => $to]));
        $refunds = self::orderLevelSums(self::refundLeanBase(['date_from' => $from, 'date_to' => $to]));

        return (object) ['discount' => $sales->discount - $refunds->discount, 'tax' => $sales->tax - $refunds->tax];
    }

    /**
     * Grand total = net (pra-pajak, tanpa diskon tingkat-order, keputusan O3) − diskon tingkat-order + pajak.
     * Satu-satunya rumus; dipakai laporan, Dashboard, dan pengurangan refund supaya selalu sama dengan struk.
     */
    public static function grandTotal(float $net, float $orderDiscount, float $tax): float
    {
        return $net - $orderDiscount + $tax;
    }

    /**
     * Penjualan bersih per tanggal bisnis di rentang [from, to] → ['Y-m-d' => '123.00'].
     * Hari tanpa penjualan TIDAK ada di hasil; pemanggil mengisi nol.
     *
     * @return array<string, string>
     */
    public static function netByDay(string $from, string $to): array
    {
        $net = self::leanBase(['date_from' => $from, 'date_to' => $to])
            ->selectRaw('orders.business_date as day, SUM(order_items.line_total) as net')
            ->groupBy('orders.business_date')
            ->pluck('net', 'day')
            ->map(fn ($v) => (float) $v)
            ->all();

        // 048 / S4 — refund mengurangi penjualan pada TANGGAL REFUND (hari yang sudah lewat tidak berubah).
        foreach (self::refundNetByDay($from, $to) as $day => $refunded) {
            $net[$day] = ($net[$day] ?? 0.0) - (float) $refunded;
        }

        return array_map(fn ($v) => number_format((float) $v, 2, '.', ''), $net);
    }

    /**
     * 048 / S4 — basis refund (order_items ⨝ orders yang DIREFUND) dengan join dan filter yang sama dengan
     * leanBase(). Tanggal = tanggal bisnis REFUND (`orders.refund_business_date`), bukan tanggal penjualan.
     */
    public static function refundLeanBase(array $filters = []): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')
            ->whereNotNull('orders.refunded_at')
            ->where('order_items.data_mode', ModeGate::current())
            ->when(true, fn ($q) => OutletFilter::apply($q, 'orders.outlet_id'))
            ->when(! empty($filters['date_from']), fn ($q) => $q->where('orders.refund_business_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->where('orders.refund_business_date', '<=', $filters['date_to']));
    }

    /**
     * 048 / S4 — basis refund untuk LAPORAN (join dan filter outlet pelaporan, sama dengan posBase()).
     */
    public static function refundBase(array $filters = []): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->tap(fn ($q) => OutletFilter::joinReportOutlet($q))
            ->where('orders.status', 'completed')
            ->whereNotNull('orders.refunded_at')
            ->where('order_items.data_mode', ModeGate::current())
            ->when(true, fn ($q) => OutletFilter::applyReport($q))
            ->when(! empty($filters['date_from']), fn ($q) => $q->where('orders.refund_business_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->where('orders.refund_business_date', '<=', $filters['date_to']));
    }

    /**
     * Refund netto (pendapatan) per tanggal bisnis REFUND di [from, to] → ['Y-m-d' => float].
     *
     * @return array<string, float>
     */
    public static function refundNetByDay(string $from, string $to): array
    {
        return self::refundLeanBase(['date_from' => $from, 'date_to' => $to])
            ->selectRaw('orders.refund_business_date as day, SUM(order_items.line_total) as net')
            ->groupBy('orders.refund_business_date')
            ->pluck('net', 'day')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Pengurangan refund untuk LAPORAN pada [from, to] (tanggal bisnis refund): gross, net (pendapatan), pajak,
     * dan biaya barang (`cost`). Satu-satunya tempat angka refund dihitung untuk laporan; pemanggil memakai
     * netOfRefunds() atau membaca `cost` untuk laba rugi.
     *
     * 048 / S4 (keputusan pemilik) — refund selalu seluruh order, jadi pajaknya = pajak order yang direfund,
     * dihitung sekali per order. Biaya barang ikut dikurangi pada tanggal refund: ini hanya akuntansi,
     * stok sudah kembali oleh RefundService.
     */
    public static function refundTotals(?string $from, ?string $to): object
    {
        $base = self::refundBase(['date_from' => $from, 'date_to' => $to]);

        $row = (clone $base)
            ->selectRaw('SUM(order_items.line_total + order_items.discount_amount) as gross, SUM(order_items.line_total) as net, SUM(order_items.cost_price * order_items.qty) as cost')
            ->first();

        $orderLevel = self::orderLevelSums($base);

        return (object) [
            'gross' => (float) ($row->gross ?? 0),
            'net' => (float) ($row->net ?? 0),
            'cost' => (float) ($row->cost ?? 0),
            'discount' => $orderLevel->discount,
            'tax' => $orderLevel->tax,
        ];
    }

    /**
     * Kurangi refund dari totalan penjualan (gross, net, diskon tingkat-order, pajak) untuk [from, to]. Panggil SEBELUM
     * angka diformat. Refund mengurangi grand total dengan nilai yang sama seperti penjualannya (net − diskon + pajak).
     * Units dan jumlah order tetap menghitung transaksi (keputusan: refund hanya mengurangi uang).
     */
    public static function netOfRefunds(object $totals, ?string $from, ?string $to): object
    {
        $refund = self::refundTotals($from, $to);
        $totals->gross_sales = (float) ($totals->gross_sales ?? 0) - $refund->gross;
        $totals->net_sales = (float) ($totals->net_sales ?? 0) - $refund->net;
        $totals->order_discount_total = (float) ($totals->order_discount_total ?? 0) - $refund->discount;
        $totals->tax_total = (float) ($totals->tax_total ?? 0) - $refund->tax;
        $totals->grand_total = self::grandTotal((float) $totals->net_sales, (float) $totals->order_discount_total, (float) $totals->tax_total);

        return $totals;
    }

    /**
     * Refund per kelompok laporan (sama dengan `GROUP BY` laporan): ['<id atau tanggal>' => float].
     * `$idExpr` adalah ekspresi kunci grup laporan; untuk pengelompokan per hari, kuncinya tanggal REFUND.
     *
     * @return array<string, float>
     */
    public static function refundAmountsByGroup(string $idExpr, ?string $from, ?string $to): array
    {
        $refundIdExpr = $idExpr === 'orders.business_date' ? 'orders.refund_business_date' : $idExpr;

        return self::refundBase(['date_from' => $from, 'date_to' => $to])
            ->selectRaw("{$refundIdExpr} as group_key, SUM(order_items.line_total) as net")
            ->groupBy(DB::raw($refundIdExpr))
            ->pluck('net', 'group_key')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Refund per outlet PELAPORAN (recap) pada [from, to] → [outlet_id => ['sales' => float]].
     *
     * @return array<int, array{sales: float}>
     */
    public static function refundSalesByReportOutlet(?string $from, ?string $to): array
    {
        return self::refundBase(['date_from' => $from, 'date_to' => $to])
            ->selectRaw(OutletFilter::reportOutletSql().' as report_outlet_id, SUM(order_items.line_total) as sales')
            ->groupBy(DB::raw(OutletFilter::reportOutletSql()))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->report_outlet_id => ['sales' => (float) $row->sales]])
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
     * @return list<array{at: string, net: float, discount: float, tax: float}>  `at` = completed_at (UTC, 'Y-m-d H:i:s'); `discount` = diskon tingkat-order dan `tax` = pajak order (keduanya negatif untuk refund)
     */
    public static function completedNetBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        $sales = self::leanBase()
            ->where('orders.completed_at', '>=', $start->utc()->format('Y-m-d H:i:s'))
            ->where('orders.completed_at', '<', $end->utc()->format('Y-m-d H:i:s'))
            ->selectRaw('orders.id, orders.completed_at as at, SUM(order_items.line_total) as net, MAX(orders.discount_amount) as discount, MAX(orders.tax_amount) as tax')
            ->groupBy('orders.id', 'orders.completed_at')
            ->get()
            ->map(fn ($r) => ['at' => (string) $r->at, 'net' => (float) $r->net, 'discount' => (float) $r->discount, 'tax' => (float) $r->tax])
            ->all();

        // 048 / S4 — refund dalam jendela ini muncul sebagai pengurangan pada instan refund itu sendiri.
        $refunds = self::refundLeanBase()
            ->where('orders.refunded_at', '>=', $start->utc()->format('Y-m-d H:i:s'))
            ->where('orders.refunded_at', '<', $end->utc()->format('Y-m-d H:i:s'))
            ->selectRaw('orders.id, orders.refunded_at as at, SUM(order_items.line_total) as net, MAX(orders.discount_amount) as discount, MAX(orders.tax_amount) as tax')
            ->groupBy('orders.id', 'orders.refunded_at')
            ->get()
            ->map(fn ($r) => ['at' => (string) $r->at, 'net' => -(float) $r->net, 'discount' => -(float) $r->discount, 'tax' => -(float) $r->tax])
            ->all();

        return array_merge($sales, $refunds);
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
