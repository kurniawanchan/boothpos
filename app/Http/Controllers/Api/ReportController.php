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
            ->map(function ($row) use ($idAlias) {
                $data = (array) $row;
                // Untuk group_by=day tidak ada entitas untuk ditaut — id
                // dikembalikan null secara eksplisit alih-alih menghapus
                // key-nya, supaya bentuk baris tetap konsisten antar
                // group_by dan frontend tidak perlu isset() check.
                $data['entity_id'] = $idAlias === null ? null : $data['entity_id'];
                $data['amount'] = number_format((float) $data['amount'], 2, '.', '');

                return $data;
            });

        $totals = (clone $base)->selectRaw('
            COUNT(DISTINCT orders.id) as order_count,
            SUM(order_items.qty) as unit_count,
            SUM(order_items.line_total + order_items.discount_amount) as gross_sales,
            SUM(order_items.discount_amount) as discount_total,
            SUM(order_items.line_total) as net_sales
        ')->first();

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

        $revenue = (float) ($totals->revenue ?? 0);
        $cost = (float) ($totals->cost_of_goods ?? 0);
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
                    'order_id' => $first->order_id,
                    'order_number' => $first->order_number,
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
                    'order_total_for_artist' => number_format(
                        (float) $rowsForOrder->sum('line_total'), 2, '.', ''
                    ),
                ];
            })
            ->values();

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
     */
    public function stockByArtist(Request $request): JsonResponse
    {
        if (! $request->user()->canAccessMenu('reports')) {
            return response()->json(['message' => __('reports.not_authorized')], 403);
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
            ->when($request->filled('artist_id'), fn ($q) => $q->where('artists.id', $request->integer('artist_id')))
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
        if (! in_array($report, ['sales', 'profit', 'artist-settlements', 'artist-profit'], true)) {
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
}
