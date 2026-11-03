<?php

namespace App\Services;

use App\Support\ModeGate;
use App\Support\PreorderRecognizedRevenue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 054 (US10) — laporan penjualan per PRODUK dengan penanda "Terlaris", pencarian, dan filter
 * seller / kategori / produk.
 *
 * SUMBER (permintaan pemilik produk 2026-11-02: "gabungkan transaksi dengan transaksi pre-order"):
 *  1. penjualan POS yang selesai (completed, tidak batal) — unit = SUM(qty), nilai = SUM(line_total);
 *  2. pre-order yang tidak dibatalkan, memakai SATU-SATUNYA definisi pendapatan pre-order
 *     (`PreorderRecognizedRevenue`, fitur 010/076): hanya porsi yang SUDAH TERBAYAR (qty & nilai dikali
 *     collected/subtotal), jadi pre-order yang belum dibayar tidak menggelembungkan laporan. Pre-order
 *     tanpa event tidak muncul (definisi itu meng-inner-join events) — konsisten dengan laporan lain.
 * Unit pre-order dibulatkan di tingkat produk/varian lewat aturan SISA (fitur 033) — `units` = POS + pre-order
 * selalu persis; jangan dibulatkan per baris.
 *
 * Query tangan `DB::table` tidak mengikuti scope DEMO/LIVE Eloquent, jadi `data_mode` disaring EKSPLISIT.
 * Join polos juga membuat produk/varian yang sudah dihapus tetap muncul dengan nama aslinya.
 * Tidak ada modal/margin sama sekali di sini.
 *
 * Aturan Terlaris: 3 produk teratas menurut UNIT, seri pada batas ikut ditandai, minimal 1 unit. Peringkat
 * dihitung atas himpunan hasil filter seller/kategori/produk (jadi "terlaris di seller ini"), TIDAK atas
 * pencarian teks — kalau tidak, mencari "pin" akan menobatkan produk apa pun yang cocok.
 * Varian dengan unit terbanyak dalam satu produk ditandai `is_top` (seri ikut ditandai).
 */
class SalesByProductReport
{
    public const BEST_SELLER_TOP = 3;

    /**
     * @param  array{event_id?:mixed,date_from?:mixed,date_to?:mixed,q?:mixed,artist_id?:mixed,category_id?:mixed,product_id?:mixed}  $filters
     */
    public function build(array $filters): array
    {
        $all = $this->productRows($filters);

        // Pilihan filter diturunkan dari cakupan event/tanggal (sebelum filter seller/kategori/produk),
        // supaya layar tak butuh endpoint daftar terpisah dan hanya menawarkan yang punya penjualan.
        $options = [
            'artists' => $all->unique('artist_id')->map(fn ($r) => ['value' => $r['artist_id'], 'label' => $r['artist_name']])->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'categories' => $all->filter(fn ($r) => $r['category_id'] !== null)->unique('category_id')->map(fn ($r) => ['value' => $r['category_id'], 'label' => $r['category_name']])->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'products' => $all->map(fn ($r) => ['value' => $r['product_id'], 'label' => $r['product_name']])->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        ];

        $artistIds = $this->ids($filters['artist_id'] ?? null);
        $categoryIds = $this->ids($filters['category_id'] ?? null);
        $productIds = $this->ids($filters['product_id'] ?? null);

        $filtered = $all
            ->when($artistIds !== [], fn (Collection $c) => $c->filter(fn ($r) => in_array($r['artist_id'], $artistIds, true)))
            ->when($categoryIds !== [], fn (Collection $c) => $c->filter(fn ($r) => in_array($r['category_id'], $categoryIds, true)))
            ->when($productIds !== [], fn (Collection $c) => $c->filter(fn ($r) => in_array($r['product_id'], $productIds, true)))
            ->values();

        $rows = $this->rank($filtered);

        // Pencarian bekerja pada nama produk ATAU nama salah satu variannya; angka produk tetap utuh.
        $term = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($term !== '') {
            $rows = $rows->filter(fn (array $r) => str_contains(mb_strtolower($r['product_name']), $term)
                || collect($r['variants'])->contains(fn ($v) => str_contains(mb_strtolower((string) $v['variant_name']), $term)));
        }
        $rows = $rows->values();

        return [
            'scope' => [
                'event_id' => isset($filters['event_id']) && $filters['event_id'] !== '' ? (int) $filters['event_id'] : null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ],
            'best_seller_rule' => ['top' => self::BEST_SELLER_TOP, 'metric' => 'units', 'ties_included' => true],
            'options' => $options,
            'totals' => [
                'units' => $rows->sum('units'),
                'pos_units' => $rows->sum('pos_units'),
                'preorder_units' => $rows->sum('preorder_units'),
                'sales' => $this->money($rows->sum(fn ($r) => (float) $r['sales'])),
                'pos_sales' => $this->money($rows->sum(fn ($r) => (float) $r['pos_sales'])),
                'preorder_sales' => $this->money($rows->sum(fn ($r) => (float) $r['preorder_sales'])),
                'transaction_count' => $this->distinctTotal($filters, $rows->pluck('product_id')->all()),
                'stock_in' => $rows->sum('stock_in'),
                'stock_remaining' => $rows->sum('stock_remaining'),
            ],
            'data' => $rows->all(),
        ];
    }

    /**
     * Transaksi (penjualan POS DAN pre-order) yang memuat satu produk — hanya baris produk itu, terbaru dulu.
     * `q` (opsional) menyaring baris ke varian yang namanya cocok, kecuali nama produknya sendiri cocok.
     *
     * @return array{data:array,meta:array}
     */
    public function transactions(int $productId, array $filters, int $page, int $perPage): array
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $like = $term !== '' ? '%'.addcslashes($term, '%_\\').'%' : null;
        $matchTerm = fn ($q) => $q->where(fn ($w) => $w->where('products.name', 'like', $like)->orWhere('product_variants.variant_name', 'like', $like));

        $sales = $this->posLines($filters)->where('products.id', $productId)
            ->when($like !== null, $matchTerm)
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->selectRaw('orders.id as doc_id, orders.order_number as doc_number, orders.created_at, users.name as cashier_name')
            ->selectRaw('SUM(order_items.qty) as qty, SUM(order_items.line_total) as amount')
            ->groupBy('orders.id', 'orders.order_number', 'orders.created_at', 'users.name')
            ->get()
            ->map(fn ($r) => $this->txRow('sale', $r, (float) $r->qty, (float) $r->amount));

        $preorders = $this->preorderLines($filters)->where('products.id', $productId)
            ->when($like !== null, $matchTerm)
            ->leftJoin('users', 'users.id', '=', 'preorders.user_id')
            ->selectRaw('preorders.id as doc_id, preorders.preorder_number as doc_number, preorders.created_at, users.name as cashier_name')
            ->selectRaw('SUM(preorder_items.qty * '.PreorderRecognizedRevenue::FRACTION_EXPR.') as qty')
            ->selectRaw('SUM(preorder_items.line_total * '.PreorderRecognizedRevenue::FRACTION_EXPR.') as amount')
            ->groupBy('preorders.id', 'preorders.preorder_number', 'preorders.created_at', 'users.name')
            ->get()
            ->filter(fn ($r) => (float) $r->amount > 0 || (float) $r->qty > 0) // pre-order belum dibayar: belum diakui
            ->map(fn ($r) => $this->txRow('preorder', $r, (float) $r->qty, (float) $r->amount));

        $merged = $sales->concat($preorders)->sortByDesc(fn ($r) => $r['created_at'].sprintf('%012d', $r['id']))->values();
        $total = $merged->count();

        return [
            'data' => $merged->forPage($page, $perPage)->values()->all(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    private function txRow(string $kind, object $r, float $qty, float $amount): array
    {
        return [
            'kind' => $kind,
            'id' => (int) $r->doc_id,
            // `order_id`/`order_number` tetap ada demi kompatibilitas layar lama; untuk pre-order berisi id/nomor pre-order.
            'order_id' => (int) $r->doc_id,
            'order_number' => $r->doc_number,
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            'cashier_name' => $r->cashier_name,
            'qty' => (int) round($qty),
            'amount' => $this->money($amount),
        ];
    }

    /**
     * Satu baris per PRODUK (sudah menggabungkan POS + pre-order, dengan rincian varian), belum diperingkat.
     *
     * @return Collection<int, array>
     */
    private function productRows(array $filters): Collection
    {
        $select = 'products.id as product_id, products.name as product_name, products.artist_id as artist_id, artists.name as artist_name, '
            .'categories.id as category_id, categories.name as category_name, product_variants.id as variant_id, product_variants.variant_name as variant_name';
        $group = ['products.id', 'products.name', 'products.artist_id', 'artists.name', 'categories.id', 'categories.name', 'product_variants.id', 'product_variants.variant_name'];

        $pos = $this->posLines($filters)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw($select)
            ->selectRaw('SUM(order_items.qty) as units, SUM(order_items.line_total) as sales')
            ->groupBy($group)->get();

        $pre = $this->preorderLines($filters)
            ->selectRaw($select)
            ->selectRaw('SUM(preorder_items.qty * '.PreorderRecognizedRevenue::FRACTION_EXPR.') as units')
            ->selectRaw('SUM(preorder_items.line_total * '.PreorderRecognizedRevenue::FRACTION_EXPR.') as sales')
            ->groupBy($group)->get();

        // Gabung per varian, lalu per produk.
        $byProduct = [];
        foreach (['pos' => $pos, 'pre' => $pre] as $source => $set) {
            foreach ($set as $r) {
                $p = &$byProduct[$r->product_id];
                $p ??= [
                    'product_id' => (int) $r->product_id, 'product_name' => $r->product_name,
                    'artist_id' => (int) $r->artist_id, 'artist_name' => $r->artist_name,
                    'category_id' => $r->category_id !== null ? (int) $r->category_id : null, 'category_name' => $r->category_name,
                    'pos_units' => 0.0, 'pre_units' => 0.0, 'pos_sales' => 0.0, 'pre_sales' => 0.0, 'variants' => [],
                ];
                $v = &$p['variants'][$r->variant_id];
                $v ??= ['variant_id' => (int) $r->variant_id, 'variant_name' => $r->variant_name, 'pos_units' => 0.0, 'pre_units' => 0.0, 'pos_sales' => 0.0, 'pre_sales' => 0.0];
                $v[$source.'_units'] += (float) $r->units;
                $v[$source.'_sales'] += (float) $r->sales;
                $p[$source.'_units'] += (float) $r->units;
                $p[$source.'_sales'] += (float) $r->sales;
                unset($p, $v);
            }
        }

        $docCounts = $this->distinctDocCounts($filters);
        $stock = $this->stockFigures(array_keys($byProduct));

        return collect($byProduct)->map(function (array $p) use ($docCounts, $stock) {
            $posUnits = (int) round($p['pos_units']);
            $units = (int) round($p['pos_units'] + $p['pre_units']);
            $variants = collect($p['variants'])->map(function (array $v) use ($stock) {
                $vPos = (int) round($v['pos_units']);
                $vUnits = (int) round($v['pos_units'] + $v['pre_units']);

                return [
                    'variant_id' => $v['variant_id'], 'variant_name' => $v['variant_name'],
                    'units' => $vUnits, 'pos_units' => $vPos, 'preorder_units' => $vUnits - $vPos,
                    'sales' => $this->money($v['pos_sales'] + $v['pre_sales']),
                    'pos_sales' => $this->money($v['pos_sales']), 'preorder_sales' => $this->money($v['pre_sales']),
                    'stock_in' => $stock['variants'][$v['variant_id']]['in'] ?? 0,
                    'stock_remaining' => $stock['variants'][$v['variant_id']]['remaining'] ?? 0,
                ];
            })->sortByDesc('units')->values();
            $top = (int) ($variants->max('units') ?? 0);
            $variants = $variants->map(fn ($v) => $v + ['is_top' => $top > 0 && $v['units'] === $top && $variants->count() > 1])->all();

            return [
                'product_id' => $p['product_id'], 'product_name' => $p['product_name'],
                'artist_id' => $p['artist_id'], 'artist_name' => $p['artist_name'],
                'category_id' => $p['category_id'], 'category_name' => $p['category_name'],
                'units' => $units, 'pos_units' => $posUnits, 'preorder_units' => $units - $posUnits,
                'sales' => $this->money($p['pos_sales'] + $p['pre_sales']),
                'pos_sales' => $this->money($p['pos_sales']), 'preorder_sales' => $this->money($p['pre_sales']),
                'transaction_count' => $docCounts[$p['product_id']] ?? 0,
                // Stok seluruh varian aktif produk (bukan hanya yang terjual): sisa stok per produk harus utuh.
                'stock_in' => $stock['products'][$p['product_id']]['in'] ?? 0,
                'stock_remaining' => $stock['products'][$p['product_id']]['remaining'] ?? 0,
                'variants' => $variants,
            ];
        })->filter(fn (array $r) => $r['units'] > 0 || (float) $r['sales'] > 0)->values();
    }

    /**
     * Stok masuk & sisa stok per varian dan per produk (varian aktif, tidak dihapus). Bukan angka
     * per-event: stok adalah keadaan saat ini, jadi tidak ikut filter event/tanggal laporan.
     *  - masuk  = Σ qty_change > 0 bertipe initial / purchase / adjustment (retur dari pembatalan
     *             TIDAK dihitung: itu pengembalian penjualan, bukan stok baru);
     *  - sisa   = product_variants.current_stock (sumber tunggal stok, dikelola StockService).
     * Query tangan: `data_mode` disaring eksplisit.
     *
     * @param  array<int,int|string>  $productIds
     * @return array{variants:array<int,array{in:int,remaining:int}>,products:array<int,array{in:int,remaining:int}>}
     */
    private function stockFigures(array $productIds): array
    {
        if ($productIds === []) {
            return ['variants' => [], 'products' => []];
        }

        $variants = DB::table('product_variants')
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->where('data_mode', ModeGate::current())
            ->get(['id', 'product_id', 'current_stock']);

        $in = DB::table('stock_movements')
            ->whereIn('variant_id', $variants->pluck('id'))
            ->where('data_mode', ModeGate::current())
            ->where('qty_change', '>', 0)
            ->whereIn('type', ['initial', 'purchase', 'adjustment'])
            ->selectRaw('variant_id, SUM(qty_change) as total')
            ->groupBy('variant_id')
            ->pluck('total', 'variant_id');

        $byVariant = [];
        $byProduct = [];
        foreach ($variants as $v) {
            $figure = ['in' => (int) ($in[$v->id] ?? 0), 'remaining' => (int) $v->current_stock];
            $byVariant[$v->id] = $figure;
            $byProduct[$v->product_id]['in'] = ($byProduct[$v->product_id]['in'] ?? 0) + $figure['in'];
            $byProduct[$v->product_id]['remaining'] = ($byProduct[$v->product_id]['remaining'] ?? 0) + $figure['remaining'];
        }

        return ['variants' => $byVariant, 'products' => $byProduct];
    }

    /** Jumlah transaksi DISTINCT (penjualan + pre-order yang sudah diakui) per produk. */
    private function distinctDocCounts(array $filters): array
    {
        $counts = [];
        $this->posLines($filters)->selectRaw('products.id as product_id, COUNT(DISTINCT orders.id) as n')->groupBy('products.id')->get()
            ->each(function ($r) use (&$counts) { $counts[$r->product_id] = ($counts[$r->product_id] ?? 0) + (int) $r->n; });
        $this->preorderLines($filters)->where('pc.collected', '>', 0)->selectRaw('products.id as product_id, COUNT(DISTINCT preorders.id) as n')->groupBy('products.id')->get()
            ->each(function ($r) use (&$counts) { $counts[$r->product_id] = ($counts[$r->product_id] ?? 0) + (int) $r->n; });

        return $counts;
    }

    /** Transaksi DISTINCT yang memuat produk-produk yang tampil (penjualan multi-produk dihitung sekali). */
    private function distinctTotal(array $filters, array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        return (int) $this->posLines($filters)->whereIn('products.id', $productIds)->distinct()->count('orders.id')
            + (int) $this->preorderLines($filters)->where('pc.collected', '>', 0)->whereIn('products.id', $productIds)->distinct()->count('preorders.id');
    }

    /** Baris penjualan POS yang dihitung: selesai, mode data aktif, filter event/tanggal. */
    private function posLines(array $filters)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('artists', 'artists.id', '=', 'products.artist_id')
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current())
            ->where('orders.data_mode', ModeGate::current())
            ->when(! empty($filters['event_id']), fn ($q) => $q->where('orders.event_id', (int) $filters['event_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('orders.created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('orders.created_at', '<=', $filters['date_to']));
    }

    /** Baris pre-order yang diakui (definisi bersama); `artists` di sini = penjual produk, sama dengan POS. */
    private function preorderLines(array $filters)
    {
        $q = PreorderRecognizedRevenue::query(
            ! empty($filters['event_id']) ? (int) $filters['event_id'] : null,
            ! empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : null,
            ! empty($filters['date_to']) ? Carbon::parse($filters['date_to']) : null,
        );

        // Definisi bersama menggabungkan `artists` lewat preorder_items.artist_id; laporan ini
        // mengelompokkan menurut penjual PRODUK (sama dengan POS), jadi gabungan itu diganti.
        $q->joins = collect($q->joins)->reject(fn ($j) => $j->table === 'artists')->values()->all();

        return $q->join('artists', 'artists.id', '=', 'products.artist_id');
    }

    /** Urutan (unit terbanyak dulu; seri → nilai, lalu nama), peringkat kompetisi (1,2,2,4) dan penanda Terlaris. */
    private function rank(Collection $products): Collection
    {
        $sorted = $products->sortBy([['units', 'desc'], ['sales', 'desc'], ['product_name', 'asc']])->values();
        $units = $sorted->pluck('units')->map(fn ($u) => (int) $u);
        $cutoff = $sorted->count() >= self::BEST_SELLER_TOP ? $units[self::BEST_SELLER_TOP - 1] : ($units->last() ?? 0);

        return $sorted->map(function (array $p) use ($units, $cutoff) {
            $u = (int) $p['units'];
            $p['rank'] = 1 + $units->filter(fn ($other) => $other > $u)->count();
            $p['is_best_seller'] = $u >= 1 && $u >= $cutoff;

            return $p;
        });
    }

    /** @return array<int,int> */
    private function ids(mixed $value): array
    {
        return collect(is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]))
            ->map(fn ($v) => (int) $v)->filter()->values()->all();
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
