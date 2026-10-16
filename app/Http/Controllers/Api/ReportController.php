<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SettlementService;
use App\Services\SalesFigures;
use App\Support\ModeGate;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(private SettlementService $settlementService) {}

    /**
     * Label yang ditampilkan UI untuk setiap group_by (Task 1). Sengaja
     * satu sumber di sini, bukan di-hardcode di frontend — kalau
     * group_by baru ditambahkan di backend, frontend otomatis dapat
     * label yang benar tanpa perlu tahu daftarnya sendiri.
     */
    private const GROUP_LABELS = [
        'product' => 'Produk',
        'category' => 'Kategori',
        'outlet' => 'Outlet',
        'day' => 'Tanggal',
        // 009-ui-ux-refinements (US6/FR-016) — dipakai Dashboard untuk
        // statistik per-pelanggan (tabel + chart). Baris untuk grouping
        // ini punya bentuk berbeda (customer_id/customer_name/
        // transaction_count/total_amount, bukan entity_id/label/
        // unit_count/amount generik) karena "transaksi" di sini berarti
        // COUNT(DISTINCT orders.id), bukan SUM(order_items.qty) — lihat
        // cabang group_by === 'customer' di bawah.
        'customer' => 'Pelanggan',
    ];

    /**
     * 046 — periode laporan: `date_from`/`date_to` = tanggal BISNIS (Y-m-d). Laporan yang berskala periode
     * (profit, rekap, modal outlet) memakai hari bisnis berjalan bila keduanya kosong; satu sisi yang
     * diisi saja berarti rentang terbuka di sisi lainnya.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function period(Request $request, bool $defaultToday = true): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $from = $validated['date_from'] ?? null;
        $to = $validated['date_to'] ?? null;

        if ($defaultToday && $from === null && $to === null) {
            $from = $to = BusinessDay::today();
        }

        return [$from, $to];
    }

    public function sales(Request $request): JsonResponse
    {
        $groupBy = $request->string('group_by', 'product')->value();

        if (! array_key_exists($groupBy, self::GROUP_LABELS)) {
            $groupBy = 'product';
        }

        // Halaman Sales memuat tanpa batas bila tanggal kosong ("semua"); SPA mengirim hari ini secara bawaan.
        [$from, $to] = $this->period($request, defaultToday: false);

        // 042-dashboard-revamp — dasar query POS (order `completed` ⨝ item, filter data_mode eksplisit
        // dan tanggal BISNIS) hidup di SalesFigures supaya Dashboard memakai angka yang sama persis.
        $base = SalesFigures::posBase(['date_from' => $from, 'date_to' => $to]);

        // Task 1 — setiap baris agregat kini membawa entity_id (product_id/
        // category_id/outlet_id) di samping label, supaya frontend bisa
        // menaut baris ke halaman detail entitas.
        [$idExpr, $labelExpr] = match ($groupBy) {
            'category' => ['categories.id', 'categories.name'],
            'outlet' => ['outlets.id', 'outlets.name'],
            'day' => ['orders.business_date', 'orders.business_date'],
            default => ['products.id', 'products.name'],
        };

        if ($groupBy === 'customer') {
            // 009-ui-ux-refinements (US6/T046) — satu query GROUP BY per orders.customer_id.
            // LEFT JOIN customers (bukan INNER) karena customer_id nullable untuk pembeli walk-in —
            // baris "walk-in" tetap muncul dengan customer_id/customer_name null.
            $rows = (clone $base)
                ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
                ->selectRaw('
                    orders.customer_id as customer_id,
                    customers.name as customer_name,
                    COUNT(DISTINCT orders.id) as transaction_count,
                    SUM(order_items.line_total) as total_amount
                ')
                ->groupBy('orders.customer_id', 'customers.name')
                ->orderByDesc('total_amount')
                ->get()
                ->map(function ($row) {
                    $data = (array) $row;
                    $data['total_amount'] = number_format((float) $data['total_amount'], 2, '.', '');

                    return $data;
                });
        } else {
            $rows = (clone $base)
                ->selectRaw("
                    {$idExpr} as entity_id,
                    {$labelExpr} as label,
                    SUM(order_items.qty) as unit_count,
                    SUM(order_items.line_total) as amount
                ")
                ->groupBy(DB::raw($idExpr))
                ->orderByDesc('amount')
                ->get()
                ->map(fn ($row) => [
                    // group_by=day: kuncinya tanggal itu sendiri, bukan id entitas.
                    'entity_id' => $groupBy === 'day' ? null : $row->entity_id,
                    'label' => $row->label,
                    'unit_count' => (float) $row->unit_count,
                    'amount' => number_format((float) $row->amount, 2, '.', ''),
                ]);
        }

        // 048 / S4 — refund mengurangi penjualan pada tanggal refund (lihat SalesFigures::refundTotals). Baris
        // kelompok dikurangi dengan kunci yang sama; hari yang hanya berisi refund tetap muncul sebagai pengurangan.
        if ($groupBy !== 'customer') {
            $refundByKey = SalesFigures::refundAmountsByGroup($idExpr, $from, $to);
            $rows = $rows->map(function ($row) use ($groupBy, $refundByKey) {
                $key = $groupBy === 'day' ? (string) $row['label'] : (string) $row['entity_id'];
                $row['amount'] = number_format((float) $row['amount'] - ($refundByKey[$key] ?? 0.0), 2, '.', '');

                return $row;
            });
            if ($groupBy === 'day') {
                $haveDays = $rows->pluck('label')->map(fn ($d) => (string) $d)->all();
                foreach ($refundByKey as $day => $net) {
                    if (! in_array((string) $day, $haveDays, true)) {
                        $rows->push(['entity_id' => null, 'label' => $day, 'unit_count' => 0.0, 'amount' => number_format(-$net, 2, '.', '')]);
                    }
                }
            }
        }

        $totals = SalesFigures::netOfRefunds(SalesFigures::totals($base), $from, $to);

        // 046: tidak ada lagi pendapatan pre-order, jadi angka bersama = angka POS. Kunci `pos_*` tetap
        // dikirim supaya klien/ekspor yang memakainya tidak berubah.
        $totals->unit_count = (float) ($totals->unit_count ?? 0);
        $totals->gross_sales = number_format((float) ($totals->gross_sales ?? 0), 2, '.', '');
        $totals->net_sales = number_format((float) ($totals->net_sales ?? 0), 2, '.', '');
        $totals->pos_unit_count = $totals->unit_count;
        $totals->pos_gross_sales = $totals->gross_sales;
        $totals->pos_net_sales = $totals->net_sales;

        // 048 / S4 — pajak eksklusif: net (pra-pajak) + pajak = grand_total, sama dengan struk. order_discount_total
        // ditampilkan supaya selisih diskon tingkat-order (yang tidak masuk net, keputusan O3) terlihat.
        $totals->tax_total = number_format((float) ($totals->tax_total ?? 0), 2, '.', '');
        $totals->grand_total = number_format((float) ($totals->grand_total ?? 0), 2, '.', '');
        $totals->order_discount_total = number_format((float) ($totals->order_discount_total ?? 0), 2, '.', '');

        // Daftar TRANSAKSI dibangun oleh SalesTransactionsService — sumber yang SAMA dengan ekspor,
        // supaya berkas ekspor tidak pernah berbeda dari layar.
        $built = app(\App\Services\SalesTransactionsService::class)->build([
            'date_from' => $from,
            'date_to' => $to,
            'include_voided' => $request->input('include_voided'),
        ], $request->user());

        return response()->json([
            'group_by' => $groupBy,
            'group_label' => self::GROUP_LABELS[$groupBy],
            'totals' => $totals,
            'rows' => $rows,
            'transactions' => $built['transactions'],
            'sessions' => $built['sessions'],
        ]);
    }

    /**
     * Ekspor transaksi halaman Sales: PERSIS baris yang tampil di layar (hanya order POS).
     * Klien hanya mengirim KUNCI baris ("order:12") — nominalnya tidak pernah dipercaya;
     * seluruh isi berkas dibangun ulang dari database lewat SalesTransactionsService (sumber
     * yang sama dengan daftar). Kunci yang tak ada / di luar filter tanggal diabaikan, bukan
     * dibuatkan baris. Tanpa `keys` = seluruh transaksi sesuai filter.
     *
     * Kontak pelanggan (telepon/email) SENGAJA tidak ikut (lihat catatan GenericArrayExport);
     * modal & margin hanya untuk yang boleh melihat laporan (ditentukan service).
     */
    public function exportSalesTransactions(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'include_voided' => ['nullable', 'boolean'],
            'keys' => ['nullable', 'array', 'max:5000'],
            'keys.*' => ['string', 'regex:/^order:\d+$/'],
        ]);

        $rows = app(\App\Services\SalesTransactionsService::class)
            ->build($validated, $request->user(), detail: true)['transactions']
            ->map(function (array $r) {
                $row = [
                    'transaction_no' => $r['order_number'],
                    'status' => $r['status'],
                    'time' => $r['created_at'],
                    'customer' => $r['customer_name'],
                    'sellers' => implode(', ', $r['outlet_names']->all()),
                    'cashier' => $r['cashier_name'],
                    'items' => $r['items_text'],
                    'units' => $r['unit_count'],
                    // Angka (bukan string) supaya bisa dijumlah langsung di Excel.
                    'discount' => (float) $r['discount_amount'],
                    'payment_methods' => implode(', ', $r['payment_methods']->all()),
                    // 028 — Unpaid/Partially Paid/Fully Paid (dulu status verifikasi mentah).
                    'payment_status' => $r['payment_status'],
                    'cash' => (float) $r['cash_amount'],
                    'non_cash' => (float) $r['noncash_amount'],
                    'total' => (float) $r['total_amount'],
                    'paid' => (float) $r['paid_amount'],
                    'balance' => (float) $r['balance_amount'],
                ];
                if (array_key_exists('cost_total', $r)) {
                    $row['cost'] = (float) $r['cost_total'];
                    $row['margin'] = (float) $r['margin_amount'];
                }

                return $row;
            })->all();

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\GenericArrayExport($rows), 'transaksi-penjualan.xlsx');
    }

    public function profit(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        [$from, $to] = $this->period($request);

        $totals = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when(true, fn ($q) => \App\Support\OutletFilter::applyReport($q)) // 045 Stage 7: sama dengan baris per outlet
            ->where('orders.status', 'completed')
            ->when($from, fn ($q) => $q->where('orders.business_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', $to))
            // 003-seed-demo-live — query hand-rolled tidak mewarisi global scope Eloquent: filter eksplisit.
            ->where('order_items.data_mode', ModeGate::current())
            ->selectRaw('
                SUM(order_items.line_total) as revenue,
                SUM(order_items.cost_price * order_items.qty) as cost_of_goods
            ')->first();

        // 048 / S4 — pendapatan dan biaya barang dikurangi refund pada tanggal refund (keputusan pemilik). Refund
        // membalik penjualan DAN biaya barang yang dikembalikan, jadi laba kotor berkurang sebesar margin barang itu.
        // Stok sudah kembali lewat RefundService; bagian ini hanya akuntansi.
        $refund = SalesFigures::refundTotals($from, $to);
        $totals->revenue = (float) ($totals->revenue ?? 0) - $refund->net;
        $totals->cost_of_goods = (float) ($totals->cost_of_goods ?? 0) - $refund->cost;

        // 046 — penjualan selesai saja untuk periode ini: tanpa pre-order, tanpa biaya event / laba bersih.
        $revenue = (float) ($totals->revenue ?? 0);
        $cost = (float) ($totals->cost_of_goods ?? 0);

        return response()->json([
            'date_from' => $from,
            'date_to' => $to,
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost_of_goods' => number_format($cost, 2, '.', ''),
            'gross_profit' => number_format($revenue - $cost, 2, '.', ''),
        ]);
    }

    /**
     * 045 Stage 7 — id outlet PELAPORAN yang memuat penjualan LAMA (order `outlet_backfilled`) pada periode ini: barisnya
     * diberi `historical: true` karena atribusinya masih penjual lama (angka lampau tidak bergeser).
     *
     * @return list<int>
     */
    private function historicalOutletIds(?string $from, ?string $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when($from, fn ($q) => $q->where('orders.business_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', $to))
            ->where('orders.status', 'completed')->where('orders.outlet_backfilled', 1)
            ->where('order_items.data_mode', ModeGate::current())
            ->selectRaw(\App\Support\OutletFilter::reportOutletSql().' as oid')->distinct()->pluck('oid')->map(fn ($i) => (int) $i)->all();
    }

    public function outletSettlements(Request $request): JsonResponse
    {
        // Sejalan dengan PRD 7.13 (kasir tidak boleh mengakses laporan modal/keuntungan): rekap hasil outlet
        // digerbang sama seperti profit().
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        [$from, $to] = $this->period($request);

        // 046 — dihitung LANGSUNG dari penjualan selesai pada periode (tidak ada lagi snapshot per event).
        $sales = $this->settlementService->posSalesForPeriod($from, $to);

        // Outlet aktif TETAP muncul walau belum laku (nol), supaya "belum ada penjualan" terbedakan dari
        // "tidak terdaftar"; outlet nonaktif/terhapus muncul bila punya penjualan pada periode ini.
        // 045 Stage 7 — hanya outlet yang berlaku untuk pemanggil (penugasan ∩ pilihan header);
        // `entitledIds()` supaya outlet yang SUDAH dinonaktifkan tetap tampil bila punya penjualan.
        $entitled = app(\App\Support\OutletContext::class)->entitledIds();
        $outlets = Outlet::withTrashed()
            ->where(function ($q) use ($sales) {
                $q->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))
                    ->orWhereIn('id', $sales->keys()->all());
            })
            ->when($entitled !== null, fn ($q) => $q->whereIn('id', $entitled ?: [0]))
            ->orderBy('name')
            ->get();

        // Rekap hanya Penjual / Unit / Penjualan; `outlet_id` (selalu ada) adalah kunci baris untuk UI.
        $historical = $this->historicalOutletIds($from, $to);
        $data = $outlets->map(function (Outlet $outlet) use ($sales, $historical) {
            $row = $sales->get($outlet->id);

            return [
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
                // 045 Stage 7: memuat penjualan sebelum ada outlet penjualan (atribusi penjual lama).
                'historical' => in_array($outlet->id, $historical, true),
                'total_sales' => number_format((float) ($row['sales'] ?? 0), 2, '.', ''),
                'total_units' => (int) round((float) ($row['units'] ?? 0)),
            ];
        })->values();

        return response()->json(['date_from' => $from, 'date_to' => $to, 'data' => $data]);
    }

    /**
     * F11.6 — drill-down transaksi yang menyusun rekap satu outlet pada satu
     * periode. Sengaja dipisah dari outletSettlements() (bukan menumpuk
     * ?with_transactions=1 di sana) karena bentuknya beda tujuan: yang satu
     * tabel ringkasan seluruh outlet, yang ini daftar order milik SATU
     * outlet — memuatnya sekaligus di setiap baris ringkasan akan menarik
     * N+1 order untuk outlet yang tidak sedang dilihat penggunanya.
     *
     * PENTING (kasus multi-outlet per order) — satu order booth ini bisa
     * berisi item dari BEBERAPA outlet sekaligus (order_items.home_outlet_id
     * per baris, bukan per order). Baris yang dikembalikan di sini HARUS
     * disaring where('order_items.home_outlet_id', $outlet->id) sebelum
     * dikelompokkan per order, supaya order dengan item dari outlet lain
     * tidak ikut ditampilkan atau nilainya tidak tercampur ke outlet ini.
     */
    /**
     * 040-recap-pos-transactions-only — detail transaksi seorang seller pada
     * suatu periode: HANYA penjualan POS, supaya jumlahnya persis sama dengan
     * kolom Penjualan pada baris rekap (yang juga POS-saja).
     */
    public function outletSettlementTransactions(Request $request, Outlet $outlet): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        [$from, $to] = $this->period($request);

        $items = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->when($from, fn ($q) => $q->where('orders.business_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', $to))
            ->when(true, fn ($q) => \App\Support\OutletFilter::applyReport($q))
            ->where('orders.status', 'completed')
            ->whereRaw(\App\Support\OutletFilter::reportOutletSql().' = ?', [$outlet->id])
            // BUG YANG DITEMUKAN & DIPERBAIKI (012, R1) — query join
            // hand-rolled ini sebelumnya TIDAK PUNYA filter data_mode sama
            // sekali, berbeda dari semua query join lain di controller ini.
            // Ditambahkan di sini sejalan dengan aturan codebase ("query
            // hand-rolled tidak mewarisi Eloquent global scope").
            ->where('order_items.data_mode', ModeGate::current())
            ->orderByDesc('orders.created_at')
            ->get([
                'orders.id as order_id',
                'orders.order_number',
                'orders.created_at as order_created_at',
                'order_items.name_snapshot',
                'order_items.sku_snapshot',
                'order_items.qty',
                'order_items.line_total',
            ]);

        $transactions = $items
            ->groupBy('order_id')
            ->map(function ($rowsForOrder) {
                $first = $rowsForOrder->first();

                return [
                    'key' => 'order-'.$first->order_id,
                    'number' => $first->order_number,
                    'created_at' => $first->order_created_at
                        ? \Illuminate\Support\Carbon::parse($first->order_created_at)->toIso8601String()
                        : null,
                    // Hanya item MILIK ARTIST INI dalam order tersebut —
                    // bukan seluruh isi order (lihat komentar kelas di atas).
                    'items' => $rowsForOrder->map(fn ($r) => [
                        'sku' => $r->sku_snapshot,
                        'name' => $r->name_snapshot,
                        'qty' => (int) $r->qty,
                        'line_total' => number_format((float) $r->line_total, 2, '.', ''),
                    ])->values(),
                    'amount_for_outlet' => number_format(
                        (float) $rowsForOrder->sum('line_total'), 2, '.', ''
                    ),
                ];
            })
            ->values();

        return response()->json([
            'date_from' => $from,
            'date_to' => $to,
            'outlet' => ['id' => $outlet->id, 'name' => $outlet->name],
            'transactions' => $transactions,
        ]);
    }

    /**
     * F9.5 — modal dan laba kotor PER OUTLET untuk satu periode tanggal bisnis.
     *
     * Basis modal = order_items.cost_price (SNAPSHOT saat transaksi), sama dengan profit(), supaya laporan
     * historis tidak berubah diam-diam ketika data master (termasuk BOM) berubah, dan kedua laporan
     * modal/keuntungan tidak menyimpang satu sama lain. Modal BOM (bom_cost) tetap tersedia lewat endpoint
     * cost-breakdown-nya sendiri.
     */
    public function outletProfit(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        [$from, $to] = $this->period($request);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->tap(fn ($q) => \App\Support\OutletFilter::joinReportOutlet($q)) // 045 Stage 7
            ->when($from, fn ($q) => $q->where('orders.business_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', $to))
            ->when(true, fn ($q) => \App\Support\OutletFilter::applyReport($q))
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat catatan sales()
            ->selectRaw('
                outlets.id as outlet_id,
                outlets.name as outlet_name,
                SUM(order_items.line_total) as total_sales,
                SUM(order_items.cost_price * order_items.qty) as modal
            ')
            ->groupBy('outlets.id', 'outlets.name')
            ->orderBy('outlets.name')
            ->get();

        $historical = $this->historicalOutletIds($from, $to);
        $data = $rows->map(fn ($r) => [
            'outlet_id' => $r->outlet_id,
            'outlet_name' => $r->outlet_name,
            'historical' => in_array((int) $r->outlet_id, $historical, true), // 045 Stage 7
            'total_sales' => number_format((float) $r->total_sales, 2, '.', ''),
            'modal' => number_format((float) $r->modal, 2, '.', ''),
            'gross_profit' => number_format((float) $r->total_sales - (float) $r->modal, 2, '.', ''),
        ])->values();

        return response()->json(['date_from' => $from, 'date_to' => $to, 'data' => $data]);
    }

    /**
     * 006-purchase-order-and-ops (US9) — laporan pembelian, dipisah dari
     * outletProfit()/profit() karena sumbernya `purchase_orders`, bukan
     * `order_items`. Query pakai Eloquent (bukan DB::table() seperti
     * sales()) karena PurchaseOrder sudah HasDataMode — global scope
     * otomatis menyaring data_mode, tidak perlu where() eksplisit di sini.
     */
    public function purchases(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        $query = \App\Models\PurchaseOrder::query()
            ->with('vendor')
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')));

        $rows = (clone $query)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'vendor_name' => $po->vendor?->name,
                'status' => $po->status,
                'created_at' => $po->created_at?->toIso8601String(),
                'total_amount' => number_format((float) $po->total_amount, 2, '.', ''),
            ]);

        $totals = (clone $query)->selectRaw('
            COUNT(*) as po_count,
            SUM(total_amount) as total_amount
        ')->first();

        return response()->json([
            'rows' => $rows,
            'totals' => [
                'po_count' => (int) $totals->po_count,
                'total_amount' => number_format((float) $totals->total_amount, 2, '.', ''),
            ],
        ]);
    }

    /**
     * 006-purchase-order-and-ops (US10) — stok per outlet. Mulai dari
     * `artists` (bukan `product_variants`) dengan LEFT JOIN, meniru pola
     * outletSettlements() di controller ini — supaya outlet yang belum
     * punya produk/stok sama sekali TETAP muncul (variant_count 0,
     * total_stock 0) alih-alih hilang dari laporan (spec Acceptance
     * Scenario 3). BUG YANG DITEMUKAN & DIPERBAIKI (revisi awal) — global
     * scope `DataModeScope` Eloquent HANYA berlaku untuk tabel model yang
     * di-query langsung (`artists`), BUKAN untuk `products`/
     * `product_variants` yang di-JOIN manual di sini — sama seperti
     * catatan di ReportController@sales, jadi tetap butuh where()
     * data_mode eksplisit untuk kedua tabel itu, meski keduanya
     * HasDataMode di level model.
     *
     * 009-ui-ux-refinements (US7) — drilldown opsional lewat ?outlet_id=.
     * Sengaja MENAMBAH satu level ke join outlet→products→variants yang
     * sudah ada di atas (bukan query paralel baru), supaya kedua mode
     * (ringkasan semua outlet vs. detail satu outlet) selalu konsisten
     * terhadap sumber data yang sama. outlet_id divalidasi lewat
     * Outlet::findOrFail() (bukan sekadar exists:outlets,id) supaya
     * global scope DataModeScope otomatis menolak outlet_id yang valid
     * di database tapi milik mode DEMO/LIVE lain — respons 404, sama
     * seperti pola findOrFail() lain di controller ini.
     */
    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI (012,
     * ditemukan lewat test T022) — stockByOutlet() mengembalikan bentuk respons
     * BERBEDA tergantung ada-tidaknya `outlet_id` di request (ringkasan per-outlet
     * dengan key 'data', vs detail per-varian tanpa key 'data' sama sekali). Jalur
     * export di export() hanya mendukung bentuk ringkasan; kalau request yang
     * diteruskan ke sana kebetulan membawa `outlet_id` (mis. dipanggil manual atau
     * lewat query string lama), Excel::download akan 500 dengan
     * "Undefined array key data". Helper ini memaksa jalur ringkasan dengan
     * membuat instance Request BARU tanpa `outlet_id`, bukan menimpa query string
     * request yang sedang diproses — export laporan stok per-outlet secara desain
     * memang selalu berbentuk ringkasan, tidak pernah berbasis satu outlet.
     */
    private function stockByOutletSummaryOnly(Request $request): JsonResponse
    {
        $summaryRequest = Request::create($request->fullUrlWithQuery(['outlet_id' => null]), 'GET');
        $summaryRequest->setUserResolver($request->getUserResolver());

        return $this->stockByOutlet($summaryRequest);
    }

    public function stockByOutlet(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        // 045 Stage 7 — stok adalah saldo PER OUTLET (Tahap 5): laporan ini membaca saldo outlet (`variant_outlet_stock`),
        // bukan lagi "varian milik penjual". Saldo lama dipindahkan ke outlet asal produk saat migrasi, jadi angka
        // lampau tetap sama. Hanya outlet yang berlaku untuk pemanggil.
        $context = app(\App\Support\OutletContext::class);

        if ($request->filled('outlet_id')) {
            $outlet = Outlet::findOrFail($request->integer('outlet_id'));
            abort_unless($context->user() === null || $context->isPermitted($outlet->id), 403, __('outlets.forbidden'));

            $variants = \App\Models\VariantOutletStock::query()
                ->join('product_variants', 'product_variants.id', '=', 'variant_outlet_stock.variant_id')
                ->where('variant_outlet_stock.outlet_id', $outlet->id)
                ->where('product_variants.data_mode', ModeGate::current())
                ->whereNull('product_variants.deleted_at')
                ->orderBy('product_variants.variant_name')
                ->get([
                    'product_variants.id as variant_id',
                    'product_variants.sku',
                    'product_variants.variant_name',
                    'variant_outlet_stock.current_stock',
                ]);

            return response()->json([
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
                'variants' => $variants->map(fn ($v) => [
                    'variant_id' => $v->variant_id,
                    'sku' => $v->sku,
                    'variant_name' => $v->variant_name,
                    'current_stock' => (int) $v->current_stock,
                ])->values(),
                'variant_count' => $variants->count(),
                'total_stock' => (int) $variants->sum('current_stock'),
            ]);
        }

        $rows = Outlet::query()
            ->when($context->user() !== null, fn ($q) => $q->whereIn('outlets.id', $context->effectiveIds() ?: [0]))
            ->leftJoin('variant_outlet_stock', 'variant_outlet_stock.outlet_id', '=', 'outlets.id')
            ->leftJoin('product_variants', function ($join) {
                $join->on('product_variants.id', '=', 'variant_outlet_stock.variant_id')
                    ->where('product_variants.data_mode', ModeGate::current())
                    ->whereNull('product_variants.deleted_at');
            })
            ->selectRaw('
                outlets.id as outlet_id,
                outlets.name as outlet_name,
                COUNT(product_variants.id) as variant_count,
                COALESCE(SUM(CASE WHEN product_variants.id IS NULL THEN 0 ELSE variant_outlet_stock.current_stock END), 0) as total_stock
            ')
            ->groupBy('outlets.id', 'outlets.name')
            ->orderBy('outlets.name')
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => $row->outlet_id,
                'outlet_name' => $row->outlet_name,
                'variant_count' => (int) $row->variant_count,
                'total_stock' => (int) $row->total_stock,
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * ASSUMPTION: PRD sengaja tidak menyebut nama pustaka Excel (lihat
     * PRD 9.1). Saya pilih maatwebsite/excel karena ini pustaka Excel
     * Laravel paling mapan — belum diverifikasi jalan di sandbox ini,
     * perlu `composer require maatwebsite/excel` di lingkungan lokal.
     */
    public function export(Request $request, string $report)
    {
        if (! in_array($report, ['sales', 'profit', 'outlet-settlements', 'outlet-profit', 'purchases', 'stock-by-outlet'], true)) {
            return response()->json(['message' => __('reports.unknown_report')], 404);
        }

        // F11.6 — 'outlet-settlements' punya kebutuhan ekspor berbeda dari
        // tiga laporan lain di sini (satu sheet ringkasan + satu sheet
        // detail transaksi), jadi ditangani lewat jalurnya sendiri alih-alih
        // dipaksa masuk ke pola GenericArrayExport satu-sheet di bawah —
        // sejalan dengan catatan di docblock GenericArrayExport sendiri.
        if ($report === 'outlet-settlements') {
            return $this->exportOutletSettlements($request);
        }

        // BUG YANG DITEMUKAN & DIPERBAIKI — match() di bawah sebelumnya
        // tidak punya cabang 'profit' walau route mengizinkan nilai itu
        // (lihat where('report', 'sales|profit|outlet-settlements') di
        // routes/api.php). Akibatnya export profit selalu jatuh ke
        // 'default' dan diam-diam menghasilkan file kosong tanpa galat
        // apa pun — tidak ketahuan kecuali membuka isi file-nya.
        [$response, $dataKey, $filename] = match ($report) {
            'sales' => [$this->sales($request), 'rows', 'laporan-penjualan.xlsx'],
            'profit' => [$this->profit($request), null, 'laporan-profit.xlsx'],
            'outlet-profit' => [$this->outletProfit($request), 'data', 'laporan-modal-outlet.xlsx'],
            'purchases' => [$this->purchases($request), 'rows', 'laporan-pembelian.xlsx'],
            // BUG YANG DITEMUKAN & DIPERBAIKI (012,
            // ditemukan via test T022) — stockByOutlet() mengembalikan bentuk RESPONS
            // BERBEDA saat query string membawa `outlet_id` (detail per-varian, tanpa
            // key 'data') vs tanpa `outlet_id` (ringkasan per-outlet, key 'data'). Jalur
            // export ini HANYA mendukung bentuk ringkasan — request()->query('outlet_id')
            // dihapus dulu supaya export selalu memanggil mode ringkasan, apa pun query
            // string yang menyertainya, alih-alih 500 "Undefined array key data".
            'stock-by-outlet' => [$this->stockByOutletSummaryOnly($request), 'data', 'laporan-stok-per-outlet.xlsx'],
        };

        // profit(), outletProfit() menegakkan otorisasinya sendiri (403
        // untuk kasir). Galat itu WAJIB diteruskan apa adanya, bukan
        // ditelan lalu diam-diam mengekspor berkas kosong — kalau tidak,
        // batasan akses laporan modal/keuntungan (PRD 7.13) bisa dilewati
        // lewat endpoint export ini.
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = json_decode($response->getContent(), true);

        // Laporan profit berbentuk satu objek ringkasan (bukan daftar
        // baris) — dibungkus jadi satu baris supaya tetap kompatibel
        // dengan GenericArrayExport yang mengasumsikan array of rows.
        $rows = $dataKey === null ? [$payload] : $payload[$dataKey];

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\GenericArrayExport($rows),
            $filename
        );
    }

    /**
     * F11.6 — ekspor rekap outlet dua sheet: "Rekap" (bentuk lama, tidak
     * berubah, supaya tidak memutus apa pun yang sudah membaca berkas
     * lama) dan "Detail Transaksi" (baru — satu baris flat per item per
     * order, lintas SEMUA outlet pada periode ini). Sheet-per-outlet sengaja
     * TIDAK dipakai — jumlah outlet per event tidak terbatas, dan
     * MultiSheetArrayExport dirancang untuk daftar sheet yang diketahui
     * sebelumnya, bukan satu sheet per baris data.
     */
    private function exportOutletSettlements(Request $request)
    {
        $summaryResponse = $this->outletSettlements($request);

        if ($summaryResponse->getStatusCode() !== 200) {
            return $summaryResponse;
        }

        $summaryRows = json_decode($summaryResponse->getContent(), true)['data'];

        [$from, $to] = $this->period($request);

        $detailRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->tap(fn ($q) => \App\Support\OutletFilter::joinReportOutlet($q)) // 045 Stage 7
            ->when($from, fn ($q) => $q->where('orders.business_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('orders.business_date', '<=', $to))
            ->when(true, fn ($q) => \App\Support\OutletFilter::applyReport($q))
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat catatan sales()
            ->orderBy('outlets.name')
            ->orderBy('orders.created_at')
            ->get([
                'outlets.name as outlet_name',
                'orders.order_number',
                'orders.created_at',
                'order_items.name_snapshot as item_name',
                'order_items.qty',
                'order_items.line_total',
            ])
            ->map(fn ($r) => [
                'outlet_name' => $r->outlet_name,
                'order_number' => $r->order_number,
                'date' => \Illuminate\Support\Carbon::parse($r->created_at)->toDateTimeString(),
                'item_name' => $r->item_name,
                'qty' => (int) $r->qty,
                'line_total' => number_format((float) $r->line_total, 2, '.', ''),
            ])
            ->all();

        // 040 — baris diambil dari outletSettlements() di atas, jadi nilainya
        // pasti sama dengan layar (POS-saja); empat kolom POS/pre-order dari
        // 033 sudah tidak ada. Sheet "Detail Transaksi" memang sejak awal
        // POS-saja sehingga kini cocok dengan ringkasannya.
        // 045 Stage 7 — `historical` (1 = memuat penjualan sebelum ada outlet penjualan) ikut diekspor: berkas = layar.
        $summaryHeadings = ['outlet_id', 'outlet_name', 'historical', 'total_sales', 'total_units'];
        $summaryRows = array_map(fn ($r) => array_merge($r, ['historical' => ($r['historical'] ?? false) ? 1 : 0]), $summaryRows);
        $detailHeadings = ['outlet_name', 'order_number', 'date', 'item_name', 'qty', 'line_total'];

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\MultiSheetArrayExport([
                new \App\Exports\SheetArrayExport('Rekap', $summaryHeadings, $summaryRows),
                new \App\Exports\SheetArrayExport('Detail Transaksi', $detailHeadings, $detailRows),
            ]),
            'rekap-outlet.xlsx'
        );
    }
}
