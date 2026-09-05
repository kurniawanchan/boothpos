<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\ArtistSettlement;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\SettlementService;
use App\Support\ModeGate;
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
        'artist' => 'Artist',
        'day' => 'Tanggal',
        // 005-ux-enhancements-dashboard (US2) — grafik "penjualan per
        // event" di dashboard butuh pengelompokan ini; ditambahkan di
        // sini (bukan endpoint baru) supaya tetap satu jalur agregasi
        // penjualan yang sudah teruji mode-scoping-nya, bukan duplikat.
        'event' => 'Event',
        // 009-ui-ux-refinements (US6/FR-016) — dipakai Dashboard untuk
        // statistik per-pelanggan (tabel + chart). Baris untuk grouping
        // ini punya bentuk berbeda (customer_id/customer_name/
        // transaction_count/total_amount, bukan entity_id/label/
        // unit_count/amount generik) karena "transaksi" di sini berarti
        // COUNT(DISTINCT orders.id), bukan SUM(order_items.qty) — lihat
        // cabang group_by === 'customer' di bawah.
        'customer' => 'Pelanggan',
    ];

    public function sales(Request $request): JsonResponse
    {
        $groupBy = $request->string('group_by', 'product')->value();

        if (! array_key_exists($groupBy, self::GROUP_LABELS)) {
            $groupBy = 'product';
        }

        $base = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            // BUG YANG DITEMUKAN & DIPERBAIKI — 'categories' sebelumnya
            // tidak pernah di-join, padahal group_by=category memakai
            // products.category_id sebagai label (lihat komentar
            // "disederhanakan" yang dibuang di sini). Hasilnya, baris
            // laporan untuk pengelompokan kategori menampilkan ID mentah
            // sebagai label, bukan nama kategori — dan sebelumnya bahkan
            // selectRaw() TIDAK punya cabang 'category' sama sekali,
            // sehingga otomatis jatuh ke default 'products.name' dan
            // diam-diam mengelompokkan per PRODUK, bukan per KATEGORI,
            // walau parameter group_by=category diterima tanpa galat.
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->join('artists', 'artists.id', '=', 'order_items.artist_id')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->where('orders.status', 'completed')
            // 003-seed-demo-live (US3/FR-010) — query hand-rolled DB::table
            // TIDAK ikut Eloquent global scope (DataModeScope); tanpa filter
            // eksplisit ini, laporan tanpa event_id (mis. lihat SEMUA event)
            // akan menjumlahkan order DEMO dan LIVE sekaligus. Lihat
            // ReportDataModeIsolationTest.
            ->where('order_items.data_mode', ModeGate::current())
            ->when($request->filled('event_id'), fn ($q) => $q->where('orders.event_id', $request->integer('event_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('orders.created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('orders.created_at', '<=', $request->date('date_to')));

        // Task 1 — setiap baris agregat kini membawa entity_id (product_id/
        // category_id/artist_id) di samping label, supaya frontend bisa
        // menaut baris ke halaman detail entitas (mis. detail produk +
        // total stok, Task 2) — sebelumnya hanya label yang dipilih tanpa
        // ID apa pun untuk ditaut.
        [$idExpr, $labelExpr, $idAlias] = match ($groupBy) {
            'category' => ['categories.id', 'categories.name', 'category_id'],
            'artist' => ['artists.id', 'artists.name', 'artist_id'],
            'event' => ['events.id', 'events.name', 'event_id'],
            'day' => ['DATE(orders.created_at)', 'DATE(orders.created_at)', null],
            default => ['products.id', 'products.name', 'product_id'],
        };

        // 010-split-payment-preorder-reports (US5/FR-011/FR-012, research.md
        // R1) — Preorder yang belum lunas TIDAK boleh menyumbang nilai
        // penuh line_total-nya ke laporan (itu barang yang belum tentu
        // dibayar lunas). Uang yang diakui dibatasi pada yang BENAR-BENAR
        // terkumpul di tabel `payments` (bukan cache preorders.paid_amount,
        // yang bisa basi — lihat komentar SettlementService), lalu
        // diproporsikan ke tiap item sesuai porsi line_total-nya terhadap
        // subtotal preorder. Preorder 'cancelled' selalu menyumbang nol.
        // Query terpisah (bukan loop per-preorder) yang di-agregasi lalu
        // digabung SEKALI ke $rows/$totals di PHP di bawah — bukan
        // UNION ALL, karena order_items dan preorder_items punya kolom
        // (discount_amount) yang tidak simetris, dan penggabungan di PHP
        // di sini tetap satu query tambahan, bukan N+1.
        $preorderBase = $this->preorderRecognizedRevenueBase($request);

        [$poIdExpr, $poLabelExpr] = match ($groupBy) {
            'category' => ['categories.id', 'categories.name'],
            'artist' => ['artists.id', 'artists.name'],
            'event' => ['events.id', 'events.name'],
            // Tidak ada tanggal pembayaran tunggal untuk preorder (uang bisa
            // masuk bertahap) — sebagai proksi kami pakai tanggal preorder
            // DIBUAT, konsisten dengan bagaimana 'day' pada order memakai
            // tanggal transaksi terjadi. ASUMSI yang didokumentasikan di
            // sini karena tidak ada satu tanggal "benar" untuk uang yang
            // terkumpul dari beberapa pembayaran terpisah.
            'day' => ['DATE(preorders.created_at)', 'DATE(preorders.created_at)'],
            default => ['products.id', 'products.name'],
        };

        $fraction = self::PREORDER_FRACTION_EXPR;
        $preorderRows = (clone $preorderBase)
            ->selectRaw("
                {$poIdExpr} as entity_id,
                {$poLabelExpr} as label,
                SUM(preorder_items.qty * ({$fraction})) as unit_count,
                SUM(preorder_items.line_total * ({$fraction})) as amount
            ")
            ->groupBy(DB::raw($poIdExpr))
            ->get();

        $rows = (clone $base)
            ->selectRaw("
                {$idExpr} as entity_id,
                {$labelExpr} as label,
                SUM(order_items.qty) as unit_count,
                SUM(order_items.line_total) as amount
            ")
            ->groupBy(DB::raw($idExpr))
            ->get();

        // Gabung baris order + preorder yang sudah masing-masing diagregasi
        // di SQL, dikunci oleh entity_id (atau label untuk group_by=day yang
        // entity_id-nya selalu null).
        $merged = [];
        foreach ($rows as $row) {
            $key = $idAlias === null ? $row->label : $row->entity_id;
            $merged[$key] = [
                'entity_id' => $idAlias === null ? null : $row->entity_id,
                'label' => $row->label,
                'unit_count' => (float) $row->unit_count,
                'amount' => (float) $row->amount,
            ];
        }
        foreach ($preorderRows as $row) {
            $key = $idAlias === null ? $row->label : $row->entity_id;
            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'entity_id' => $idAlias === null ? null : $row->entity_id,
                    'label' => $row->label,
                    'unit_count' => 0.0,
                    'amount' => 0.0,
                ];
            }
            $merged[$key]['unit_count'] += (float) $row->unit_count;
            $merged[$key]['amount'] += (float) $row->amount;
        }

        $rows = collect(array_values($merged))
            ->sortByDesc('amount')
            ->values()
            ->map(function (array $data) {
                $data['amount'] = number_format($data['amount'], 2, '.', '');

                return $data;
            });
        if ($groupBy === 'customer') {
            // 009-ui-ux-refinements (US6/T046) — satu query GROUP BY per
            // orders.customer_id, sama seperti pola artist/day di atas
            // (bukan loop per-customer, Constitution Principle V).
            // LEFT JOIN customers (bukan INNER) karena customer_id
            // nullable untuk pembeli walk-in — baris "walk-in" tetap
            // muncul dengan customer_id/customer_name null, bukan hilang
            // diam-diam dari laporan. Tidak perlu filter data_mode
            // tambahan pada 'customers': order_items.data_mode sudah
            // menyaring baris sumbernya, dan customer_id yang tersimpan
            // pada order sudah divalidasi lintas-mode saat order dibuat
            // (lihat CLAUDE.md, catatan customer_id re-fetch di
            // OrderService/PreorderService).
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
        }
        // BUG YANG DITEMUKAN & DIPERBAIKI (012-seller-preorder-report-detail-export,
        // ditemukan lewat test test_sales_and_profit_reports_include_only_the_
        // collected_portion_of_a_partially_paid_preorder) — cabang `else` di sini
        // DULU menghitung ULANG `$rows` dari `$base` (order_items) SAJA, menimpa
        // hasil gabungan order+preorder yang sudah benar dari `$merged`/`$rows` di
        // baris ~173 dengan agregasi order-only. Efeknya, artist yang HANYA punya
        // preorder (tanpa satu pun order reguler) hilang total dari baris laporan
        // sales() untuk group_by selain 'customer', walau `$totals` (dihitung
        // terpisah di bawah) tetap benar menyertakan kontribusi preorder — jadi
        // total di kartu ringkasan sudah benar sementara tabel barisnya salah,
        // sebuah inkonsistensi yang mudah tidak disadari. `$rows` dari baris
        // 173-180 SUDAH benar (gabungan order+preorder, terurut, entity_id null
        // untuk group_by=day) untuk setiap group_by selain 'customer' — cabang
        // `else` yang menimpanya dihapus, bukan diperbaiki, karena computation-nya
        // memang duplikat dari yang sudah dilakukan di atas.

        $totals = (clone $base)->selectRaw('
            COUNT(DISTINCT orders.id) as order_count,
            SUM(order_items.qty) as unit_count,
            SUM(order_items.line_total + order_items.discount_amount) as gross_sales,
            SUM(order_items.discount_amount) as discount_total,
            SUM(order_items.line_total) as net_sales
        ')->first();

        $preorderTotals = (clone $preorderBase)->selectRaw("
            SUM(preorder_items.qty * ({$fraction})) as unit_count,
            SUM(preorder_items.line_total * ({$fraction})) as amount
        ")->first();

        // unit_count tetap dibiarkan numerik apa adanya (mengikuti bentuk
        // asli dari DB sebelum perubahan ini, yang juga tidak pernah
        // di-number_format) — hanya kolom uang yang wajib string 2-desimal
        // per konvensi "Money is returned as a string" di seluruh laporan
        // ini (lihat gross_sales/net_sales di bawah dan amount per baris).
        $totals->unit_count = (float) ($totals->unit_count ?? 0) + (float) ($preorderTotals->unit_count ?? 0);
        $totals->gross_sales = number_format((float) ($totals->gross_sales ?? 0) + (float) ($preorderTotals->amount ?? 0), 2, '.', '');
        $totals->net_sales = number_format((float) ($totals->net_sales ?? 0) + (float) ($preorderTotals->amount ?? 0), 2, '.', '');

        $event = $request->filled('event_id') ? Event::find($request->integer('event_id')) : null;

        // Task 1 — daftar TRANSAKSI (satu baris = satu order 'completed'),
        // terpisah dari tabel agregat per-produk/kategori/artist/hari di
        // atas. Ini yang sebelumnya hilang: KPI "Transaksi: 3" dihitung
        // dari COUNT(DISTINCT orders.id) di atas, tapi tidak ada satu pun
        // endpoint yang mengembalikan baris per-order — jadi kalau dua
        // dari tiga order kebetulan membeli produk yang sama, tabel
        // agregat produk (2 baris) terlihat "berkontradiksi" dengan KPI
        // (3 transaksi), padahal keduanya sama-sama benar, cuma menjawab
        // pertanyaan berbeda. `id` disertakan di setiap baris supaya
        // frontend bisa memanggil GET /orders/{id}/receipt untuk struk
        // transaksi mana pun di daftar ini (Task 3), bukan cuma yang baru
        // saja dibuat.
        $transactions = Order::query()
            // F10.6 — eager-load 'customer' di samping 'cashier' yang sudah
            // ada, supaya frontend punya nama pelanggan untuk disaring saat
            // mengetik kata kunci. Pencarian sendiri sengaja TIDAK dibangun
            // di sini: kriteria penerimaan F10.6 eksplisit menyebut "tanpa
            // perlu memuat ulang seluruh laporan", yang berarti penyaringan
            // dilakukan di frontend atas array transactions[] yang sudah
            // diambil, bukan lewat parameter query baru di endpoint ini.
            // 003-seed-demo-live follow-up (FR-018) — 'items.artist' juga
            // di-eager-load supaya frontend punya nama artist per transaksi
            // untuk disaring, sejalan dengan 'customer' di atas (F10.6).
            ->with(['cashier', 'customer', 'items.artist'])
            ->withCount('items')
            ->where('status', 'completed')
            ->when($request->filled('event_id'), fn ($q) => $q->where('event_id', $request->integer('event_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at?->toIso8601String(),
                'cashier_name' => $order->cashier?->name,
                // customer_id nullable (pembeli walk-in tidak wajib punya
                // data pelanggan) — null di sini apa adanya, bukan galat.
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer?->name,
                // Follow-up 2 (FR-022) — dipakai popover detail customer di
                // Sales tanpa perlu endpoint GET /customers/{id} baru
                // (CustomerController hanya expose index/store/update).
                'customer_phone' => $order->customer?->phone,
                'customer_email' => $order->customer?->email,
                'item_count' => $order->items_count,
                'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
                // FR-018/FR-019 — nama-nama artist unik yang punya barang di
                // transaksi ini, dipakai frontend untuk pencarian per
                // artist DAN untuk ditampilkan di baris tabel.
                'artist_names' => $order->items->pluck('artist.name')->filter()->unique()->values(),
            ]);

        return response()->json([
            'event' => $event,
            'group_by' => $groupBy,
            'group_label' => self::GROUP_LABELS[$groupBy],
            'totals' => $totals,
            'rows' => $rows,
            'transactions' => $transactions,
        ]);
    }

    /**
     * 010-split-payment-preorder-reports (US5, research.md R1) — porsi
     * subtotal preorder yang sudah BENAR-BENAR terkumpul di `payments`
     * (bukan cache preorders.paid_amount). Dipakai sebagai faktor pengali
     * line_total/qty/cost_price tiap preorder_items di titik pemakaian —
     * satu ekspresi SQL yang dievaluasi per baris hasil JOIN, bukan
     * dihitung lewat loop PHP per-preorder.
     */
    private const PREORDER_FRACTION_EXPR = 'CASE WHEN preorders.subtotal > 0 THEN COALESCE(pc.collected, 0) / preorders.subtotal ELSE 0 END';

    /**
     * Dasar query (FROM + JOIN + filter, TANPA select) yang dipakai bersama
     * oleh sales()/profit() untuk mengagregasi pendapatan preorder yang
     * diakui. Subquery `pc` menjumlahkan payments.amount per preorder,
     * mengecualikan verification='rejected' (research.md R1) — dihitung
     * SEKALI di sini lewat leftJoinSub, bukan N+1 per preorder. Preorder
     * 'cancelled' dikeluarkan sepenuhnya lewat where() di bawah, bukan
     * cuma fraction=0, supaya statusnya tidak diam-diam ikut ke-JOIN.
     */
    /**
     * 012-seller-preorder-report-detail-export (T007, data-model.md
     * "Pre-order per-seller breakdown row") — ekspresi CASE payment_completeness
     * (unpaid/partial/paid) dipakai identik oleh preorders() di path default
     * (header, alias `collected.amount_collected`) maupun path
     * `breakdown=artist` (alias `pc.collected` dari preorderRecognizedRevenueBase()),
     * supaya kedua path tidak diam-diam punya definisi "lunas" yang berbeda.
     * Diparameterkan lewat nama kolom SUM(payments.amount) karena kedua path
     * memakai alias subquery yang berbeda.
     */
    private function paymentCompletenessCaseExpr(string $collectedColumn): string
    {
        return "
            CASE
                WHEN COALESCE({$collectedColumn}, 0) <= 0 THEN 'unpaid'
                WHEN COALESCE({$collectedColumn}, 0) >= preorders.total_amount THEN 'paid'
                ELSE 'partial'
            END
        ";
    }

    private function preorderRecognizedRevenueBase(Request $request)
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
            ->when($request->filled('event_id'), fn ($q) => $q->where('preorders.event_id', $request->integer('event_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('preorders.created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('preorders.created_at', '<=', $request->date('date_to')));
    }

    public function profit(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        $eventId = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id']])['event_id'];
        $event = Event::findOrFail($eventId);

        $totals = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $eventId)
            ->where('orders.status', 'completed')
            // 003-seed-demo-live — defense-in-depth, sama seperti sales()
            // di atas (Event::findOrFail() sudah menyaring $eventId lintas
            // mode, tapi query hand-rolled ini tetap dibuat eksplisit
            // supaya tidak diam-diam benar hanya karena kebetulan digerbang
            // lookup lain).
            ->where('order_items.data_mode', ModeGate::current())
            ->selectRaw('
                SUM(order_items.line_total) as revenue,
                SUM(order_items.cost_price * order_items.qty) as cost_of_goods
            ')->first();

        // 010-split-payment-preorder-reports (US5/FR-011, research.md R1/R7)
        // — sama seperti sales(), pendapatan preorder yang diakui (uang
        // terkumpul, diproporsikan per item) ditambahkan ke revenue, dan
        // cost_price*qty-nya diproporsikan dengan RATIO YANG SAMA (bukan
        // cost_price penuh) untuk ditambahkan ke cost_of_goods — PreorderItem
        // sudah punya snapshot cost_price/sell_price/line_total yang sama
        // persis bentuknya dengan OrderItem (confirmed di PreorderItem.php).
        $fraction = self::PREORDER_FRACTION_EXPR;
        $preorderTotals = $this->preorderRecognizedRevenueBase($request)
            ->where('preorders.event_id', $eventId)
            ->selectRaw("
                SUM(preorder_items.line_total * ({$fraction})) as revenue,
                SUM(preorder_items.cost_price * preorder_items.qty * ({$fraction})) as cost_of_goods
            ")->first();

        $revenue = (float) ($totals->revenue ?? 0) + (float) ($preorderTotals->revenue ?? 0);
        $cost = (float) ($totals->cost_of_goods ?? 0) + (float) ($preorderTotals->cost_of_goods ?? 0);
        $grossProfit = $revenue - $cost;
        $netProfit = $grossProfit - (float) $event->event_cost;

        return response()->json([
            'event' => $event,
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost_of_goods' => number_format($cost, 2, '.', ''),
            'gross_profit' => number_format($grossProfit, 2, '.', ''),
            'event_cost' => number_format((float) $event->event_cost, 2, '.', ''),
            'net_profit' => number_format($netProfit, 2, '.', ''),
        ]);
    }

    public function artistSettlements(Request $request): JsonResponse
    {
        // Sejalan dengan PRD 7.13 (kasir tidak boleh mengakses laporan
        // modal/keuntungan) dan konsisten dengan profit()/
        // recordSettlementPayment() di controller ini — rekap hasil
        // artist memuat payable_amount dan deduction, data komersial yang
        // sama sensitifnya dengan laporan profit, jadi harus digerbang
        // sama, bukan cuma mutasinya (recordSettlementPayment) saja.
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        $eventId = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id']])['event_id'];
        $event = Event::findOrFail($eventId);

        // Selalu dihitung ulang agar angka live, bukan dibaca mentah dari
        // cache — konsisten dengan keputusan desain SettlementService.
        $this->settlementService->recalculateForEvent($event);

        $settlements = ArtistSettlement::where('event_id', $eventId)->get()->keyBy('artist_id');

        // BUG YANG DITEMUKAN & DIPERBAIKI — laporan ini dulu membaca
        // artist_settlements saja, sedangkan SettlementService::
        // recalculateForEvent() membangun barisnya dari agregasi
        // GROUP BY order_items.artist_id. Artist yang belum punya satu pun
        // order_items di event ini karena itu TIDAK PERNAH punya baris
        // settlement, dan hilang total dari laporan — operator tidak bisa
        // membedakan "artist ini belum laku" dari "artist ini tidak ikut
        // event". Perbaikannya dilakukan di sisi LAPORAN (left join semua
        // artist aktif ke settlement-nya), bukan dengan menyemai baris
        // artist_settlements kosong untuk setiap artist: tabel itu berperan
        // sebagai catatan STATUS PEMBAYARAN ke artist, jadi baris yang
        // tidak pernah punya nilai bayar hanya akan jadi sampah yang
        // mengaburkan maknanya.
        //
        // Artist yang sudah dinonaktifkan/di-soft-delete TETAP muncul bila
        // punya baris settlement di event ini — uangnya tetap wajib
        // dibayar, dan versi lama malah fatal error di $s->artist->name
        // untuk artist yang sudah terhapus.
        $artistIdsWithSettlement = $settlements->keys()->all();

        $artists = Artist::withTrashed()
            ->where(function ($q) use ($artistIdsWithSettlement) {
                $q->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'))
                    ->orWhereIn('id', $artistIdsWithSettlement);
            })
            ->orderBy('name')
            ->get();

        $data = $artists->map(function (Artist $artist) use ($settlements) {
            $s = $settlements->get($artist->id);

            $payable = (float) ($s?->payable_amount ?? 0);
            $paid = (float) ($s?->paid_amount ?? 0);

            return [
                // null HANYA untuk artist yang memang belum punya baris
                // settlement (nol penjualan). Baris yang dulu bernilai
                // angka tetap bernilai angka — 'artist_id' di bawah adalah
                // kunci baris yang selalu terisi untuk kebutuhan tabel UI.
                'id' => $s?->id,
                'artist_id' => $artist->id,
                'artist_name' => $artist->name,
                'total_sales' => number_format((float) ($s?->total_sales ?? 0), 2, '.', ''),
                'total_units' => (int) ($s?->total_units ?? 0),
                'deduction' => number_format((float) ($s?->deduction ?? 0), 2, '.', ''),
                'payable_amount' => number_format($payable, 2, '.', ''),
                'paid_amount' => number_format($paid, 2, '.', ''),
                'outstanding' => number_format($payable - $paid, 2, '.', ''),
                'status' => $s?->status ?? 'unpaid',
            ];
        })->values();

        return response()->json(['event' => $event, 'data' => $data]);
    }

    /**
     * F11.6 — drill-down transaksi yang menyusun rekap satu artist di satu
     * event. Sengaja dipisah dari artistSettlements() (bukan menumpuk
     * ?with_transactions=1 di sana) karena bentuknya beda tujuan: yang satu
     * tabel ringkasan seluruh artist, yang ini daftar order milik SATU
     * artist — memuatnya sekaligus di setiap baris ringkasan akan menarik
     * N+1 order untuk artist yang tidak sedang dilihat penggunanya.
     *
     * PENTING (kasus multi-artist per order) — satu order booth ini bisa
     * berisi item dari BEBERAPA artist sekaligus (order_items.artist_id
     * per baris, bukan per order). Baris yang dikembalikan di sini HARUS
     * disaring where('order_items.artist_id', $artist->id) sebelum
     * dikelompokkan per order, supaya order dengan item dari artist lain
     * tidak ikut ditampilkan atau nilainya tidak tercampur ke artist ini.
     */
    /**
     * 012-seller-preorder-report-detail-export (US1, research.md R1) — detail
     * transaksi seorang seller pada suatu event, MENGGABUNGKAN penjualan
     * reguler (orders) dan preorder yang sudah terkumpul pembayarannya,
     * supaya totalnya bisa ditelusuri sampai ke transaksi pembentuknya
     * (FR-001..FR-004). Dua query terpisah (order & preorder) digabung dan
     * di-sort ulang di PHP — BUKAN satu UNION SQL — karena OrderItem dan
     * PreorderItem punya kolom yang tidak simetris (lihat research.md R1
     * "Alternatives considered"), pola yang sama seperti sales()/profit().
     */
    public function artistSettlementTransactions(Request $request, Artist $artist): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        $eventId = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id']])['event_id'];
        $event = Event::findOrFail($eventId);

        $items = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $eventId)
            ->where('orders.status', 'completed')
            ->where('order_items.artist_id', $artist->id)
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

        $orderTransactions = $items
            ->groupBy('order_id')
            ->map(function ($rowsForOrder) {
                $first = $rowsForOrder->first();

                return [
                    'key' => 'order-'.$first->order_id,
                    'number' => $first->order_number,
                    'source' => 'order',
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
                    'amount_for_artist' => number_format(
                        (float) $rowsForOrder->sum('line_total'), 2, '.', ''
                    ),
                    // Dipakai hanya untuk sort gabungan di bawah, dibuang
                    // sebelum dikirim ke response.
                    '_sort_at' => $first->order_created_at,
                ];
            })
            ->values();

        // 012 (US1, R1) — sisi preorder, memakai basis query DAN rumus
        // proporsi yang SAMA PERSIS dengan sales()/profit()
        // (preorderRecognizedRevenueBase() + PREORDER_FRACTION_EXPR),
        // supaya jumlah detail ini selalu sama dengan total Seller Recap
        // yang sudah dihitung dengan rumus itu (FR-002/FR-003). Preorder
        // 'cancelled' sudah dikeluarkan di dalam base query itu sendiri
        // (FR-004).
        $fraction = self::PREORDER_FRACTION_EXPR;
        $preorderRows = $this->preorderRecognizedRevenueBase($request)
            ->where('preorders.event_id', $eventId)
            ->where('preorder_items.artist_id', $artist->id)
            ->orderByDesc('preorders.created_at')
            ->get([
                'preorders.id as preorder_id',
                'preorders.preorder_number',
                'preorders.created_at as preorder_created_at',
                'preorder_items.name_snapshot',
                'preorder_items.sku_snapshot',
                'preorder_items.qty',
                'preorder_items.line_total',
                DB::raw("(preorder_items.line_total * ({$fraction})) as recognized_amount"),
            ]);

        $preorderTransactions = $preorderRows
            ->groupBy('preorder_id')
            ->map(function ($rowsForPreorder) {
                $first = $rowsForPreorder->first();

                return [
                    'key' => 'preorder-'.$first->preorder_id,
                    'number' => $first->preorder_number,
                    'source' => 'preorder',
                    'created_at' => $first->preorder_created_at
                        ? \Illuminate\Support\Carbon::parse($first->preorder_created_at)->toIso8601String()
                        : null,
                    'items' => $rowsForPreorder->map(fn ($r) => [
                        'sku' => $r->sku_snapshot,
                        'name' => $r->name_snapshot,
                        'qty' => (int) $r->qty,
                        'line_total' => number_format((float) $r->line_total, 2, '.', ''),
                    ])->values(),
                    // Bagian artist ini dari jumlah yang SUDAH TERKUMPUL
                    // (bukan nilai penuh preorder) — FR-002/Acceptance
                    // Scenario 3.
                    'amount_for_artist' => number_format(
                        (float) $rowsForPreorder->sum('recognized_amount'), 2, '.', ''
                    ),
                    '_sort_at' => $first->preorder_created_at,
                ];
            })
            ->values();

        $transactions = $orderTransactions
            ->concat($preorderTransactions)
            ->sortByDesc(fn ($tx) => $tx['_sort_at'] ?? '')
            ->values()
            ->map(fn ($tx) => \Illuminate\Support\Arr::except($tx, ['_sort_at']));

        return response()->json([
            'event' => $event,
            'artist' => ['id' => $artist->id, 'name' => $artist->name],
            'transactions' => $transactions,
        ]);
    }

    /**
     * F9.5 — modal dan laba kotor PER ARTIST, terpisah dari biaya event.
     *
     * KEPUTUSAN DESAIN (dicatat di sini karena tidak jelas dari nama
     * method saja): PRD F9.5 sengaja tidak mewajibkan satu basis modal
     * tertentu (harga modal manual vs. modal BOM) — lihat kriteria
     * penerimaannya. Kodebase ini sudah punya pola arsitektural yang
     * konsisten: field uang/identitas di-SNAPSHOT ke order_items pada
     * saat transaksi terjadi (sell_price, cost_price, sku_snapshot,
     * name_snapshot), justru supaya laporan historis tidak diam-diam
     * berubah kalau data master (termasuk komposisi BOM) berubah setelah
     * transaksi lampau terjadi. profit() (F9.1/F9.2) sudah memakai
     * order_items.cost_price sebagai basis modal tingkat event — laporan
     * ini memakai sumber yang SAMA, hanya diiris per artist, supaya kedua
     * laporan modal/keuntungan tetap konsisten satu sama lain dan tidak
     * membangun dua jalur biaya paralel (harga modal manual vs. BOM
     * hidup) yang bisa saling menyimpang dan membingungkan pengguna.
     * Modal BOM (bom_cost, lihat BomCostCalculator) tetap tersedia lewat
     * endpoint cost-breakdown-nya sendiri untuk kebutuhan lain, tapi
     * BUKAN basis laporan historis ini.
     *
     * event_cost SENGAJA tidak dikurangkan di mana pun pada laporan ini —
     * kriteria penerimaan F9.5 eksplisit melarangnya, karena biaya
     * bersama itu sudah diperhitungkan terpisah di laba bersih tingkat
     * event (F9.3) dan akan dobel-hitung atau teralokasi tidak adil antar
     * artist bila ikut dikurangkan di sini juga.
     */
    public function artistProfit(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        $eventId = $request->validate(['event_id' => ['required', 'integer', 'exists:events,id']])['event_id'];
        $event = Event::findOrFail($eventId);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('artists', 'artists.id', '=', 'order_items.artist_id')
            ->where('orders.event_id', $eventId)
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat catatan sales()
            ->selectRaw('
                artists.id as artist_id,
                artists.name as artist_name,
                SUM(order_items.line_total) as total_sales,
                SUM(order_items.cost_price * order_items.qty) as modal
            ')
            ->groupBy('artists.id', 'artists.name')
            ->orderBy('artists.name')
            ->get()
            ->map(function ($row) {
                $totalSales = (float) $row->total_sales;
                $modal = (float) $row->modal;
                $grossProfit = $totalSales - $modal;

                return [
                    'artist_id' => $row->artist_id,
                    'artist_name' => $row->artist_name,
                    'total_sales' => number_format($totalSales, 2, '.', ''),
                    'modal' => number_format($modal, 2, '.', ''),
                    'gross_profit' => number_format($grossProfit, 2, '.', ''),
                ];
            });

        return response()->json(['event' => $event, 'data' => $rows]);
    }

    /**
     * 006-purchase-order-and-ops (US9) — laporan pembelian, dipisah dari
     * artistProfit()/profit() karena sumbernya `purchase_orders`, bukan
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
     * 006-purchase-order-and-ops (US10) — stok per artist. Mulai dari
     * `artists` (bukan `product_variants`) dengan LEFT JOIN, meniru pola
     * artistSettlements() di controller ini — supaya artist yang belum
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
     * 009-ui-ux-refinements (US7) — drilldown opsional lewat ?artist_id=.
     * Sengaja MENAMBAH satu level ke join artist→products→variants yang
     * sudah ada di atas (bukan query paralel baru), supaya kedua mode
     * (ringkasan semua artist vs. detail satu artist) selalu konsisten
     * terhadap sumber data yang sama. artist_id divalidasi lewat
     * Artist::findOrFail() (bukan sekadar exists:artists,id) supaya
     * global scope DataModeScope otomatis menolak artist_id yang valid
     * di database tapi milik mode DEMO/LIVE lain — respons 404, sama
     * seperti pola findOrFail() lain di controller ini.
     */
    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI (012-seller-preorder-report-detail-export,
     * ditemukan lewat test T022) — stockByArtist() mengembalikan bentuk respons
     * BERBEDA tergantung ada-tidaknya `artist_id` di request (ringkasan per-artist
     * dengan key 'data', vs detail per-varian tanpa key 'data' sama sekali). Jalur
     * export di export() hanya mendukung bentuk ringkasan; kalau request yang
     * diteruskan ke sana kebetulan membawa `artist_id` (mis. dipanggil manual atau
     * lewat query string lama), Excel::download akan 500 dengan
     * "Undefined array key data". Helper ini memaksa jalur ringkasan dengan
     * membuat instance Request BARU tanpa `artist_id`, bukan menimpa query string
     * request yang sedang diproses — export laporan stok per-artist secara desain
     * memang selalu berbentuk ringkasan, tidak pernah berbasis satu artist.
     */
    private function stockByArtistSummaryOnly(Request $request): JsonResponse
    {
        $summaryRequest = Request::create($request->fullUrlWithQuery(['artist_id' => null]), 'GET');
        $summaryRequest->setUserResolver($request->getUserResolver());

        return $this->stockByArtist($summaryRequest);
    }

    public function stockByArtist(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        if ($request->filled('artist_id')) {
            $artist = Artist::findOrFail($request->integer('artist_id'));

            $variants = \App\Models\ProductVariant::query()
                ->join('products', function ($join) {
                    $join->on('products.id', '=', 'product_variants.product_id')
                        ->where('products.data_mode', ModeGate::current());
                })
                ->where('products.artist_id', $artist->id)
                ->where('product_variants.data_mode', ModeGate::current())
                ->orderBy('product_variants.variant_name')
                ->get([
                    'product_variants.id as variant_id',
                    'product_variants.sku',
                    'product_variants.variant_name',
                    'product_variants.current_stock',
                ]);

            return response()->json([
                'artist_id' => $artist->id,
                'artist_name' => $artist->name,
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

        $rows = Artist::query()
            ->leftJoin('products', function ($join) {
                $join->on('products.artist_id', '=', 'artists.id')
                    ->where('products.data_mode', ModeGate::current());
            })
            ->leftJoin('product_variants', function ($join) {
                $join->on('product_variants.product_id', '=', 'products.id')
                    ->where('product_variants.data_mode', ModeGate::current());
            })
            ->selectRaw('
                artists.id as artist_id,
                artists.name as artist_name,
                COUNT(product_variants.id) as variant_count,
                COALESCE(SUM(product_variants.current_stock), 0) as total_stock
            ')
            ->groupBy('artists.id', 'artists.name')
            ->orderBy('artists.name')
            ->get()
            ->map(fn ($row) => [
                'artist_id' => $row->artist_id,
                'artist_name' => $row->artist_name,
                'variant_count' => (int) $row->variant_count,
                'total_stock' => (int) $row->total_stock,
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * 010-split-payment-preorder-reports (US6) — laporan baru khusus
     * pre-order: hitungan & nominal per status × kelengkapan pembayaran
     * (unpaid/partial/paid). Berbeda dari sales()/profit() yang hanya
     * menjumlah pendapatan pre-order yang SUDAH terbayar — laporan ini
     * tentang STATE pre-order, jadi pre-order 'cancelled' tetap tampil
     * di breakdown status meski tidak berkontribusi pendapatan.
     *
     * amount_collected dihitung live dari SUM(payments.amount) (bukan
     * cache preorders.paid_amount yang bisa drift — research.md R1/R6),
     * mengecualikan pembayaran yang verifikasinya 'rejected', sama
     * seperti aturan sales()/profit() untuk order_items.
     *
     * Satu query SQL ber-GROUP BY, bukan loop PHP per pre-order
     * (Constitution V), mengikuti pola sales() yang sudah ada.
     */
    public function preorders(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
        }

        // 012-seller-preorder-report-detail-export (US2/T007, research.md R2)
        // — path baru, terpisah total dari agregasi header di bawah, dipicu
        // oleh ?breakdown=artist. TIDAK mengubah query/shape default sama
        // sekali (FR-005 aditif, bukan pengganti).
        if ($request->query('breakdown') === 'artist') {
            return $this->preordersByArtist($request);
        }

        // 012-seller-preorder-report-detail-export (US3/T013, research.md R3,
        // data-model.md "Pre-order drilldown row") — path KETIGA, dipicu oleh
        // hadirnya `status` (dengan atau tanpa `payment_completeness`/
        // `artist_id`), mengembalikan daftar preorder individual, bukan
        // agregat. Endpoint sama (FR: reuse, bukan route baru) — dibedakan
        // lewat presence parameter, bukan ?breakdown= lain, karena drilldown
        // ini bukan "cara pengelompokan baru" seperti breakdown=artist,
        // melainkan "buka baris yang sudah dikelompokkan".
        if ($request->filled('status')) {
            return $this->preordersDrilldown($request);
        }

        $collected = DB::table('payments')
            ->selectRaw('preorder_id, SUM(amount) as amount_collected')
            ->whereNotNull('preorder_id')
            ->where('verification', '!=', 'rejected')
            ->groupBy('preorder_id');

        $completeness = $this->paymentCompletenessCaseExpr('collected.amount_collected');

        $rows = DB::table('preorders')
            ->leftJoinSub($collected, 'collected', 'collected.preorder_id', '=', 'preorders.id')
            // 003-seed-demo-live — query hand-rolled DB::table TIDAK ikut
            // Eloquent global scope, lihat catatan di sales().
            ->where('preorders.data_mode', ModeGate::current())
            ->when($request->filled('event_id'), fn ($q) => $q->where('preorders.event_id', $request->integer('event_id')))
            ->selectRaw("
                preorders.status as status,
                {$completeness} as payment_completeness,
                COUNT(*) as preorder_count,
                SUM(preorders.total_amount) as total_order_value,
                SUM(COALESCE(collected.amount_collected, 0)) as total_collected,
                SUM(GREATEST(preorders.total_amount - COALESCE(collected.amount_collected, 0), 0)) as total_outstanding
            ")
            ->groupBy('preorders.status', 'payment_completeness')
            ->orderBy('preorders.status')
            ->orderBy('payment_completeness')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'payment_completeness' => $row->payment_completeness,
                'preorder_count' => (int) $row->preorder_count,
                'total_order_value' => number_format((float) $row->total_order_value, 2, '.', ''),
                'total_collected' => number_format((float) $row->total_collected, 2, '.', ''),
                'total_outstanding' => number_format((float) $row->total_outstanding, 2, '.', ''),
            ]);

        return response()->json(['rows' => $rows]);
    }

    /**
     * 012-seller-preorder-report-detail-export (US2/T007, data-model.md
     * "Pre-order per-seller breakdown row", research.md R2) — breakdown
     * per-artis dari preorders(), dipicu oleh ?breakdown=artist.
     *
     * Beda dari path default: JOIN ke preorder_items (via
     * preorderRecognizedRevenueBase(), dipakai ulang dari 010 supaya tidak
     * ada rumus proporsi kedua untuk pertanyaan yang sama — "berapa bagian
     * preorder ini milik artis X"). Konsekuensinya, path ini otomatis
     * mengecualikan preorder 'cancelled' sepenuhnya (base query sudah
     * where status != cancelled), sesuai edge case spec: breakdown
     * per-seller tidak boleh memasukkan preorder cancelled ke total uang.
     *
     * total_order_value/total_collected/total_outstanding di sini adalah
     * proporsi (share) artis atas item preorder tsb, BUKAN total_amount
     * penuh header seperti path default — jumlah semua artist_id untuk
     * status/payment_completeness yang sama akan match baris ringkasan
     * path default untuk kombinasi tsb (di luar cancelled).
     *
     * preorder_count = COUNT(DISTINCT preorders.id): satu preorder dengan
     * 2 item milik artis yang sama dihitung sekali, bukan dua kali.
     *
     * Satu query SQL ber-GROUP BY (Constitution V) — tidak ada loop PHP
     * per preorder.
     */
    private function preordersByArtist(Request $request): JsonResponse
    {
        $fraction = self::PREORDER_FRACTION_EXPR;
        $completeness = $this->paymentCompletenessCaseExpr('pc.collected');

        $rows = $this->preorderRecognizedRevenueBase($request)
            ->selectRaw("
                preorder_items.artist_id as artist_id,
                artists.name as artist_name,
                preorders.status as status,
                {$completeness} as payment_completeness,
                COUNT(DISTINCT preorders.id) as preorder_count,
                SUM(preorder_items.line_total) as total_order_value,
                SUM(preorder_items.line_total * ({$fraction})) as total_collected,
                GREATEST(
                    SUM(preorder_items.line_total) - SUM(preorder_items.line_total * ({$fraction})),
                    0
                ) as total_outstanding
            ")
            ->groupBy('preorder_items.artist_id', 'artists.name', 'preorders.status', 'payment_completeness')
            ->orderBy('artists.name')
            ->orderBy('preorders.status')
            ->orderBy('payment_completeness')
            ->get()
            ->map(fn ($row) => [
                'artist_id' => (int) $row->artist_id,
                'artist_name' => $row->artist_name,
                'status' => $row->status,
                'payment_completeness' => $row->payment_completeness,
                'preorder_count' => (int) $row->preorder_count,
                'total_order_value' => number_format((float) $row->total_order_value, 2, '.', ''),
                'total_collected' => number_format((float) $row->total_collected, 2, '.', ''),
                'total_outstanding' => number_format((float) $row->total_outstanding, 2, '.', ''),
            ]);

        return response()->json(['rows' => $rows]);
    }

    /**
     * 012-seller-preorder-report-detail-export (US3/T013, research.md R3,
     * data-model.md "Pre-order drilldown row") — daftar preorder individual
     * di balik satu baris ringkasan status×payment_completeness (path
     * default), atau satu baris status×payment_completeness×artist_id
     * (path breakdown=artist) ketika `artist_id` turut diberikan.
     *
     * Tanpa artist_id: order_value/collected/outstanding adalah total milik
     * preorder itu sendiri (preorders.total_amount & SUM(payments.amount)),
     * SAMA PERSIS dengan definisi yang dipakai path default di atas
     * (paymentCompletenessCaseExpr() + agregasi collected yang sama) supaya
     * jumlah baris drilldown = baris ringkasan (invariant SC-003/FR-007).
     *
     * Dengan artist_id: order_value/collected/outstanding adalah PORSI milik
     * artis tsb, dihitung lewat helper yang SAMA dengan preordersByArtist()
     * (preorderRecognizedRevenueBase() + PREORDER_FRACTION_EXPR), di-GROUP
     * BY per preorder (bukan per item) supaya satu preorder dengan >1 item
     * milik artis yang sama tetap satu baris drilldown.
     */
    private function preordersDrilldown(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $artistId = $request->filled('artist_id') ? $request->integer('artist_id') : null;

        if ($artistId !== null) {
            $fraction = self::PREORDER_FRACTION_EXPR;
            $completeness = $this->paymentCompletenessCaseExpr('pc.collected');

            $query = $this->preorderRecognizedRevenueBase($request)
                ->leftJoin('customers', 'customers.id', '=', 'preorders.customer_id')
                ->where('preorder_items.artist_id', $artistId)
                ->where('preorders.status', $status)
                ->selectRaw("
                    preorders.id as preorder_id,
                    preorders.preorder_number as preorder_number,
                    customers.name as customer_name,
                    {$completeness} as payment_completeness,
                    SUM(preorder_items.line_total) as order_value,
                    SUM(preorder_items.line_total * ({$fraction})) as collected,
                    GREATEST(
                        SUM(preorder_items.line_total) - SUM(preorder_items.line_total * ({$fraction})),
                        0
                    ) as outstanding
                ")
                ->groupBy('preorders.id', 'preorders.preorder_number', 'customers.name', 'payment_completeness');

            if ($request->filled('payment_completeness')) {
                $query = $query->havingRaw('payment_completeness = ?', [$request->string('payment_completeness')->toString()]);
            }

            $rows = $query->orderBy('preorders.preorder_number')->get();
        } else {
            $collected = DB::table('payments')
                ->selectRaw('preorder_id, SUM(amount) as amount_collected')
                ->whereNotNull('preorder_id')
                ->where('verification', '!=', 'rejected')
                ->groupBy('preorder_id');

            $completeness = $this->paymentCompletenessCaseExpr('collected.amount_collected');

            $query = DB::table('preorders')
                ->leftJoinSub($collected, 'collected', 'collected.preorder_id', '=', 'preorders.id')
                ->leftJoin('customers', 'customers.id', '=', 'preorders.customer_id')
                ->where('preorders.data_mode', ModeGate::current())
                ->where('preorders.status', $status)
                ->when($request->filled('event_id'), fn ($q) => $q->where('preorders.event_id', $request->integer('event_id')))
                ->selectRaw("
                    preorders.id as preorder_id,
                    preorders.preorder_number as preorder_number,
                    customers.name as customer_name,
                    {$completeness} as payment_completeness,
                    preorders.total_amount as order_value,
                    COALESCE(collected.amount_collected, 0) as collected,
                    GREATEST(preorders.total_amount - COALESCE(collected.amount_collected, 0), 0) as outstanding
                ");

            if ($request->filled('payment_completeness')) {
                $query = $query->havingRaw('payment_completeness = ?', [$request->string('payment_completeness')->toString()]);
            }

            $rows = $query->orderBy('preorders.preorder_number')->get();
        }

        $rows = $rows->map(fn ($row) => [
            'preorder_id' => (int) $row->preorder_id,
            'preorder_number' => $row->preorder_number,
            'customer_name' => $row->customer_name,
            'order_value' => number_format((float) $row->order_value, 2, '.', ''),
            'collected' => number_format((float) $row->collected, 2, '.', ''),
            'outstanding' => number_format((float) $row->outstanding, 2, '.', ''),
        ]);

        return response()->json(['rows' => $rows]);
    }

    public function recordSettlementPayment(Request $request, ArtistSettlement $settlement): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized_generic')], 403);
        }

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);

        $settlement = $this->settlementService->recordPayment($settlement, (float) $validated['amount']);

        return response()->json($settlement);
    }

    /**
     * ASSUMPTION: PRD sengaja tidak menyebut nama pustaka Excel (lihat
     * PRD 9.1). Saya pilih maatwebsite/excel karena ini pustaka Excel
     * Laravel paling mapan — belum diverifikasi jalan di sandbox ini,
     * perlu `composer require maatwebsite/excel` di lingkungan lokal.
     */
    public function export(Request $request, string $report)
    {
        if (! in_array($report, ['sales', 'profit', 'artist-settlements', 'artist-profit', 'purchases', 'stock-by-artist', 'preorder'], true)) {
            return response()->json(['message' => __('reports.unknown_report')], 404);
        }

        // F11.6 — 'artist-settlements' punya kebutuhan ekspor berbeda dari
        // tiga laporan lain di sini (satu sheet ringkasan + satu sheet
        // detail transaksi), jadi ditangani lewat jalurnya sendiri alih-alih
        // dipaksa masuk ke pola GenericArrayExport satu-sheet di bawah —
        // sejalan dengan catatan di docblock GenericArrayExport sendiri.
        if ($report === 'artist-settlements') {
            return $this->exportArtistSettlements($request);
        }

        // 012-seller-preorder-report-detail-export (US4/T021, research.md R5)
        // — 'preorder' juga punya kebutuhan dua sheet (ringkasan status ×
        // kelengkapan pembayaran + breakdown per seller dari US2), jadi
        // ditangani lewat jalurnya sendiri, sama seperti 'artist-settlements'
        // di atas, alih-alih dipaksa masuk ke GenericArrayExport satu-sheet.
        if ($report === 'preorder') {
            return $this->exportPreorderReport($request);
        }

        // BUG YANG DITEMUKAN & DIPERBAIKI — match() di bawah sebelumnya
        // tidak punya cabang 'profit' walau route mengizinkan nilai itu
        // (lihat where('report', 'sales|profit|artist-settlements') di
        // routes/api.php). Akibatnya export profit selalu jatuh ke
        // 'default' dan diam-diam menghasilkan file kosong tanpa galat
        // apa pun — tidak ketahuan kecuali membuka isi file-nya.
        [$response, $dataKey, $filename] = match ($report) {
            'sales' => [$this->sales($request), 'rows', 'laporan-penjualan.xlsx'],
            'profit' => [$this->profit($request), null, 'laporan-profit.xlsx'],
            'artist-profit' => [$this->artistProfit($request), 'data', 'laporan-modal-artist.xlsx'],
            'purchases' => [$this->purchases($request), 'rows', 'laporan-pembelian.xlsx'],
            // BUG YANG DITEMUKAN & DIPERBAIKI (012-seller-preorder-report-detail-export,
            // ditemukan via test T022) — stockByArtist() mengembalikan bentuk RESPONS
            // BERBEDA saat query string membawa `artist_id` (detail per-varian, tanpa
            // key 'data') vs tanpa `artist_id` (ringkasan per-artist, key 'data'). Jalur
            // export ini HANYA mendukung bentuk ringkasan — request()->query('artist_id')
            // dihapus dulu supaya export selalu memanggil mode ringkasan, apa pun query
            // string yang menyertainya, alih-alih 500 "Undefined array key data".
            'stock-by-artist' => [$this->stockByArtistSummaryOnly($request), 'data', 'laporan-stok-per-artist.xlsx'],
        };

        // profit(), artistProfit() menegakkan otorisasinya sendiri (403
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
     * F11.6 — ekspor rekap artist dua sheet: "Rekap" (bentuk lama, tidak
     * berubah, supaya tidak memutus apa pun yang sudah membaca berkas
     * lama) dan "Detail Transaksi" (baru — satu baris flat per item per
     * order, lintas SEMUA artist di event ini). Sheet-per-artist sengaja
     * TIDAK dipakai — jumlah artist per event tidak terbatas, dan
     * MultiSheetArrayExport dirancang untuk daftar sheet yang diketahui
     * sebelumnya, bukan satu sheet per baris data.
     */
    private function exportArtistSettlements(Request $request)
    {
        $summaryResponse = $this->artistSettlements($request);

        if ($summaryResponse->getStatusCode() !== 200) {
            return $summaryResponse;
        }

        $summaryPayload = json_decode($summaryResponse->getContent(), true);
        $summaryRows = $summaryPayload['data'];

        $eventId = $request->integer('event_id');

        $detailRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('artists', 'artists.id', '=', 'order_items.artist_id')
            ->where('orders.event_id', $eventId)
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat catatan sales()
            ->orderBy('artists.name')
            ->orderBy('orders.created_at')
            ->get([
                'artists.name as artist_name',
                'orders.order_number',
                'orders.created_at',
                'order_items.name_snapshot as item_name',
                'order_items.qty',
                'order_items.line_total',
            ])
            ->map(fn ($r) => [
                'artist_name' => $r->artist_name,
                'order_number' => $r->order_number,
                'date' => \Illuminate\Support\Carbon::parse($r->created_at)->toDateTimeString(),
                'item_name' => $r->item_name,
                'qty' => (int) $r->qty,
                'line_total' => number_format((float) $r->line_total, 2, '.', ''),
            ])
            ->all();

        $summaryHeadings = ['id', 'artist_id', 'artist_name', 'total_sales', 'total_units', 'deduction', 'payable_amount', 'paid_amount', 'outstanding', 'status'];
        $detailHeadings = ['artist_name', 'order_number', 'date', 'item_name', 'qty', 'line_total'];

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\MultiSheetArrayExport([
                new \App\Exports\SheetArrayExport('Rekap', $summaryHeadings, $summaryRows),
                new \App\Exports\SheetArrayExport('Detail Transaksi', $detailHeadings, $detailRows),
            ]),
            'rekap-artist.xlsx'
        );
    }

    /**
     * 012-seller-preorder-report-detail-export (US4/T021, research.md R5)
     * — ekspor laporan Pre-order dua sheet: "Ringkasan" (agregat
     * status × kelengkapan pembayaran, bentuk lama dari preorders()) dan
     * "Per Seller" (breakdown per artist dari US2, preorders(breakdown=artist)).
     * Mengikuti pola exportArtistSettlements() persis — MultiSheetArrayExport
     * dipilih karena GenericArrayExport tidak mendukung multi-sheet, dan
     * FR-011 secara eksplisit meminta breakdown per seller ikut dalam
     * ekspor yang sama (satu unduhan, bukan dua tombol export terpisah —
     * lihat alternatif yang ditolak di research.md R5).
     */
    private function exportPreorderReport(Request $request)
    {
        $summaryResponse = $this->preorders($request);

        if ($summaryResponse->getStatusCode() !== 200) {
            return $summaryResponse;
        }

        $summaryPayload = json_decode($summaryResponse->getContent(), true);
        $summaryRows = $summaryPayload['rows'];

        // Panggil ulang preorders() dengan breakdown=artist ditambahkan ke
        // query request yang sama (merge(), bukan request baru) supaya
        // seluruh parameter lain (event_id, dll.) yang sudah ada di request
        // asli tetap ikut terbawa tanpa perlu disalin ulang manual.
        $request->merge(['breakdown' => 'artist']);
        $breakdownResponse = $this->preorders($request);

        if ($breakdownResponse->getStatusCode() !== 200) {
            return $breakdownResponse;
        }

        $breakdownPayload = json_decode($breakdownResponse->getContent(), true);
        $breakdownRows = $breakdownPayload['rows'];

        $summaryHeadings = ['status', 'payment_completeness', 'preorder_count', 'total_order_value', 'total_collected', 'total_outstanding'];
        $breakdownHeadings = ['artist_id', 'artist_name', 'status', 'payment_completeness', 'preorder_count', 'total_order_value', 'total_collected', 'total_outstanding'];

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\MultiSheetArrayExport([
                new \App\Exports\SheetArrayExport('Ringkasan', $summaryHeadings, $summaryRows),
                new \App\Exports\SheetArrayExport('Per Seller', $breakdownHeadings, $breakdownRows),
            ]),
            'laporan-preorder.xlsx'
        );
    }
}
