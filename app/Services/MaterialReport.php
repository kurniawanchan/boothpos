<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\MaterialStockMovement;
use App\Models\Order;
use App\Models\VendorMaterialPrice;
use App\Support\BusinessDay;
use App\Support\OutletFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 088 — SATU sumber angka laporan Bahan, ekspornya, dan statistik Dashboard (research D1). Semua angka diturunkan saat
 * diminta dari buku besar bahan (`material_stock_movements`) dan saldo per outlet (`material_outlet_stock`); tidak ada
 * tabel ringkasan, jadi laporan = buku besar = Dashboard (FR-012/FR-015).
 *
 * Aturan (research D2–D5):
 * - laju harian = (−sale − return − waste) di jendela ÷ HARI JUAL outlet itu (tanggal bisnis berbeda dengan pesanan
 *   selesai). Hari tutup tidak menurunkan laju. Koreksi dan pindah stok BUKAN konsumsi.
 * - status: saldo < 0 → negative; = 0 → out; laju tak diketahui/0 → unused; hari tersisa < N → low; selain itu ok.
 * - biaya satuan = aturan RecipeService::unitCost (harga rujukan vendor; setengah jadi → biaya standar), dimuat
 *   sekaligus untuk semua bahan (tanpa kueri per bahan).
 *
 * Semua kueri lewat model (global scope mode data) dan OutletFilter (outlet yang berlaku).
 */
class MaterialReport
{
    public const STATUS_RANK = ['negative' => 0, 'out' => 1, 'low' => 2, 'ok' => 3, 'unused' => 4];

    /** Jenis yang dihitung sebagai konsumsi untuk laju (research D2/D11). */
    private const CONSUMPTION = ['sale', 'return', 'waste'];

    public function __construct(private ModifierResolver $modifiers) {}

    /**
     * `outlet_ids` (opsional, catatan 2026-11-02): saringan outlet halaman; null/tanpa kunci = semua outlet yang berlaku, `[]` = tak satu pun.
     *
     * @param  array{window_days?: int, risk_days?: int, target_days?: int, kind?: array<int, string>, search?: string|null, status?: array<int, string>, outlet_ids?: list<int>|null}  $params
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function positions(array $params): array
    {
        $window = (int) ($params['window_days'] ?? 7);
        $risk = (int) ($params['risk_days'] ?? 3);
        $target = (int) ($params['target_days'] ?? 7);

        [$from, $today] = $this->windowDates($window);
        $start = BusinessDay::bounds($from)[0];
        $end = BusinessDay::bounds($today)[1];

        $outletIds = $params['outlet_ids'] ?? null;

        $balances = $this->scoped(MaterialOutletStock::query(), $outletIds)->get(['material_id', 'outlet_id', 'current_stock'])
            ->keyBy(fn ($b) => $b->material_id.':'.$b->outlet_id);

        $usage = $this->scoped(MaterialStockMovement::query(), $outletIds)
            ->whereIn('type', self::CONSUMPTION)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->groupBy('material_id', 'outlet_id')
            ->get([DB::raw('material_id'), DB::raw('outlet_id'), DB::raw('-SUM(qty_change) AS used')])
            ->keyBy(fn ($u) => $u->material_id.':'.$u->outlet_id);

        $sellingDays = $this->scoped(Order::query(), $outletIds)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereBetween('business_date', [$from, $today])
            ->groupBy('outlet_id')
            ->get([DB::raw('outlet_id'), DB::raw('COUNT(DISTINCT business_date) AS days')])
            ->pluck('days', 'outlet_id');

        $materialIds = $balances->pluck('material_id')->merge($usage->pluck('material_id'))->unique()->values();
        $materials = Material::query()->whereIn('id', $materialIds)->where('is_active', true)
            ->when(! empty($params['kind']), fn ($q) => $q->whereIn('kind', $params['kind']))
            ->when(! empty($params['search']), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$params['search'].'%')->orWhere('code', 'like', '%'.$params['search'].'%')))
            ->get()->keyBy('id');

        $reference = $this->referencePrices($materials->keys());
        $costs = $this->unitCosts($materials, $reference);
        $untracked = $this->usageUntracked($materials->keys());
        $outletCodes = \App\Models\Outlet::query()->whereIn('id', $balances->pluck('outlet_id')->merge($usage->pluck('outlet_id'))->unique())->pluck('code', 'id');

        $rows = collect();
        foreach ($balances->keys()->merge($usage->keys())->unique() as $key) {
            [$materialId, $outletId] = array_map('intval', explode(':', $key));
            $m = $materials->get($materialId);
            if ($m === null) {
                continue;
            }
            $onHand = (float) ($balances->get($key)?->current_stock ?? 0);
            $used = (float) ($usage->get($key)?->used ?? 0);
            $days = (int) ($sellingDays[$outletId] ?? 0);
            $rate = $days > 0 && $used > 0.0005 ? $used / $days : null;
            $daysLeft = $onHand <= 0 ? 0.0 : ($rate === null ? null : $onHand / $rate);
            $status = match (true) {
                $onHand < -0.0005 => 'negative',
                $onHand < 0.0005 => 'out',
                $rate === null => 'unused',
                $daysLeft < $risk => 'low',
                default => 'ok',
            };
            $cost = $costs[$materialId] ?? null;

            $rows->push([
                'material_id' => $materialId, 'code' => $m->code, 'name' => $m->name, 'unit' => $m->unit, 'kind' => $m->kind ?? 'ingredient',
                'outlet_id' => $outletId, 'outlet_code' => $outletCodes[$outletId] ?? null,
                'on_hand' => $this->qty($onHand),
                'daily_usage' => $rate === null ? null : $this->qty($rate),
                'days_left' => $daysLeft === null ? null : round($daysLeft, 1),
                'status' => $status,
                'usage_untracked' => $status === 'unused' && $untracked->has($materialId),
                'unit_cost' => $cost === null ? null : $this->money($cost),
                'stock_value' => $cost === null ? null : $this->money($onHand * $cost),
                // Diisi bagian berikutnya (menu terancam, saran beli); kunci selalu ada supaya bentuk stabil.
                'at_risk_options' => [], 'at_risk_products' => [],
                'suggestion' => $this->suggestion($rate, $onHand, $target, $reference->get($materialId)),
                '_rate' => $rate, '_on_hand' => $onHand, '_cost' => $cost,
            ]);
        }

        $rows = $this->withAtRisk($rows);

        $rows = $rows->sortBy([
            fn ($a, $b) => self::STATUS_RANK[$a['status']] <=> self::STATUS_RANK[$b['status']],
            fn ($a, $b) => ($a['days_left'] ?? INF) <=> ($b['days_left'] ?? INF),
            fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            fn ($a, $b) => $a['outlet_id'] <=> $b['outlet_id'],
        ])->values();

        $summary = $this->summarize($rows);
        if (! empty($params['status'])) {
            $rows = $rows->whereIn('status', $params['status'])->values();
        }

        return [
            'rows' => $rows->map(fn ($r) => array_diff_key($r, array_flip(['_rate', '_on_hand', '_cost'])))->all(),
            'summary' => $summary,
        ];
    }

    /**
     * 088 US1 (research D4) — untuk baris berisiko (minus/habis/akan habis): pilihan modifier AKTIF yang memakai bahan
     * itu (porsi tersisa = floor(saldo ÷ pemakaian per porsi), minus = 0, rumus yang sama dengan popup POS 087) dan
     * produk aktif yang grup EFEKTIF-nya memuat grup pilihan itu (ModifierResolver::productsUsingGroups).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function withAtRisk(Collection $rows): Collection
    {
        $risky = $rows->filter(fn ($r) => in_array($r['status'], ['negative', 'out', 'low'], true));
        if ($risky->isEmpty()) {
            return $rows;
        }

        $mappings = \App\Models\ModifierOptionMaterial::query()
            ->whereIn('material_id', $risky->pluck('material_id')->unique())
            ->whereHas('option', fn ($q) => $q->where('is_active', true)->whereHas('group', fn ($g) => $g->where('is_active', true)))
            ->with('option.group:id,name')
            ->get();
        $productsByGroup = $this->modifiers->productsUsingGroups($mappings->pluck('option.modifier_group_id')->unique()->all());

        return $rows->map(function ($r) use ($mappings, $productsByGroup) {
            if (! in_array($r['status'], ['negative', 'out', 'low'], true)) {
                return $r;
            }
            $mine = $mappings->where('material_id', $r['material_id']);
            $r['at_risk_options'] = $mine->map(fn ($m) => [
                'option_id' => $m->modifier_option_id,
                'option' => $m->option->name,
                'group' => $m->option->group?->name,
                'portions' => (float) $m->quantity > 0 ? max(0, (int) floor($r['_on_hand'] / (float) $m->quantity + 1e-9)) : 0,
            ])->sortBy('option')->values()->all();
            $r['at_risk_products'] = $mine->flatMap(fn ($m) => $productsByGroup[$m->option->modifier_group_id] ?? [])
                ->unique('product_id')->sortBy('name')->values()->all();

            return $r;
        });
    }

    /**
     * 088 US2 (research D8) — saran beli = max(0, target × laju − saldo), dibulatkan KE ATAS 3 desimal (bahan boleh
     * pecahan). Tanpa laju yang diketahui hanya kekurangan (saldo minus) yang bisa disarankan. Vendor/harga = harga
     * rujukan bahan (pilihan, lalu termurah) — sama dengan biaya satuan; tanpa harga → vendor/harga/biaya null.
     */
    private function suggestion(?float $rate, float $onHand, int $target, ?VendorMaterialPrice $price): ?array
    {
        $need = ($rate === null ? 0.0 : $target * $rate) - $onHand;
        if ($rate === null && $onHand >= 0) {
            return null;
        }
        $qty = ceil(round($need, 6) * 1000) / 1000;
        if ($qty <= 0) {
            return null;
        }
        $unit = $price?->price === null ? null : (float) $price->price;

        return [
            'qty' => $this->qty($qty),
            'vendor_id' => $price?->vendor_id,
            'vendor' => $price?->vendor?->name,
            'unit_price' => $unit === null ? null : $this->money($unit),
            'cost' => $unit === null ? null : $this->money($qty * $unit),
        ];
    }

    /**
     * 088 US3 — statistik Dashboard. Hitungan = ringkasan positions() dengan ambang BAWAAN (jendela 7, N 3), jadi sama
     * dengan laporan yang dibuka dari kartu (FR-015). Nilai terbuang = −Σ waste × biaya satuan dalam periode Dashboard.
     * Cache 30 detik dikunci mode data + outlet yang berlaku + periode + tanggal bisnis + zona/jam tutup (Konstitusi V,
     * pola DashboardService::summary); rupiah selalu dihitung dan dibuang PEMANGGIL untuk non-`reports`, supaya kunci
     * cache tidak bergantung pengguna.
     *
     * @return array<string, mixed>
     */
    public function dashboardStats(string $period): array
    {
        $key = implode(':', ['dashboard.materials', \App\Support\ModeGate::current(), $period, BusinessDay::today(), BusinessDay::timezone(), BusinessDay::cutoff(), 'outlets='.OutletFilter::cacheKey()]);

        return \Illuminate\Support\Facades\Cache::remember($key, 30, function () use ($period) {
            $summary = $this->positions(['window_days' => 7, 'risk_days' => 3, 'target_days' => 7])['summary'];
            [$from, $today] = $this->windowDates(match ($period) { '7d' => 7, '30d' => 30, default => 1 });

            $wasted = OutletFilter::apply(MaterialStockMovement::query(), 'outlet_id')->where('type', 'waste')
                ->where('created_at', '>=', BusinessDay::bounds($from)[0])->where('created_at', '<', BusinessDay::bounds($today)[1])
                ->groupBy('material_id')->get([DB::raw('material_id'), DB::raw('-SUM(qty_change) AS qty')]);
            $materials = Material::query()->whereIn('id', $wasted->pluck('material_id'))->get()->keyBy('id');
            $costs = $this->unitCosts($materials, $this->referencePrices($materials->keys()));

            return [
                'counts' => ['negative' => $summary['negative'], 'out' => $summary['out'], 'low' => $summary['low'], 'at_risk_products' => $summary['at_risk_products']],
                'low_stock_products' => \App\Services\LowStockHistory::current(),
                'stock_value' => $summary['stock_value'],
                'wasted_value' => $this->money($wasted->sum(fn ($w) => (float) $w->qty * ($costs[$w->material_id] ?? 0))),
                'params' => ['window_days' => 7, 'risk_days' => 3],
                'computed_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * 088 US4 — pemakaian per periode tanggal bisnis (research D11): per bahan per outlet, total per jenis pergerakan
     * dan nilainya; total periode HANYA rupiah (satuan bahan berbeda tidak dijumlah); tren harian penjualan bersih dan
     * terbuang (satu titik per tanggal bisnis, termasuk hari tanpa pergerakan); top 10 menurut nilai.
     *
     * @param  array{date_from: string, date_to: string, kind?: array<int, string>, search?: string|null, outlet_ids?: list<int>|null}  $params
     * @return array<string, mixed>
     */
    public function usage(array $params): array
    {
        $start = BusinessDay::bounds($params['date_from'])[0];
        $end = BusinessDay::bounds($params['date_to'])[1];
        $outletIds = $params['outlet_ids'] ?? null;
        $base = fn () => $this->scoped(MaterialStockMovement::query(), $outletIds)->where('created_at', '>=', $start)->where('created_at', '<', $end);

        $sums = $base()->groupBy('material_id', 'outlet_id', 'type')
            ->get([DB::raw('material_id'), DB::raw('outlet_id'), DB::raw('type'), DB::raw('SUM(qty_change) AS qty'),
                DB::raw('SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) AS qty_in'), DB::raw('SUM(CASE WHEN qty_change < 0 THEN -qty_change ELSE 0 END) AS qty_out')]);

        $materials = Material::query()->whereIn('id', $sums->pluck('material_id')->unique())
            ->when(! empty($params['kind']), fn ($q) => $q->whereIn('kind', $params['kind']))
            ->when(! empty($params['search']), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$params['search'].'%')->orWhere('code', 'like', '%'.$params['search'].'%')))
            ->get()->keyBy('id');
        $costs = $this->unitCosts($materials, $this->referencePrices($materials->keys()));
        $outletCodes = \App\Models\Outlet::query()->whereIn('id', $sums->pluck('outlet_id')->unique())->pluck('code', 'id');

        $rows = [];
        foreach ($sums as $s) {
            if (! $materials->has($s->material_id)) {
                continue;
            }
            $k = $s->material_id.':'.$s->outlet_id;
            $rows[$k] ??= ['material_id' => (int) $s->material_id, 'outlet_id' => (int) $s->outlet_id,
                'sale' => 0.0, 'return' => 0.0, 'waste' => 0.0, 'adj_in' => 0.0, 'adj_out' => 0.0, 'purchase' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0, 'transfer_cancel' => 0.0];
            match ($s->type) {
                'adjustment' => [$rows[$k]['adj_in'] += (float) $s->qty_in, $rows[$k]['adj_out'] += (float) $s->qty_out],
                default => $rows[$k][$s->type] = (float) $s->qty,
            };
        }

        $value = fn (float $q, int $materialId) => ($costs[$materialId] ?? null) === null ? null : $q * $costs[$materialId];
        $data = collect($rows)->map(function ($r) use ($materials, $outletCodes, $value) {
            $m = $materials[$r['material_id']];
            $sold = -($r['sale'] + $r['return']);
            $wasted = -$r['waste'];
            $out = [
                'material_id' => $r['material_id'], 'code' => $m->code, 'name' => $m->name, 'unit' => $m->unit,
                'outlet_id' => $r['outlet_id'], 'outlet_code' => $outletCodes[$r['outlet_id']] ?? null,
                'sold_net' => $this->qty($sold), 'wasted' => $this->qty($wasted),
                'adjusted_in' => $this->qty($r['adj_in']), 'adjusted_out' => $this->qty($r['adj_out']),
                'purchased' => $this->qty($r['purchase']), 'transferred_in' => $this->qty($r['transfer_in']),
                'transferred_out' => $this->qty(-$r['transfer_out']), 'transfer_cancelled' => $this->qty($r['transfer_cancel']),
            ];
            foreach (['sold_net' => $sold, 'wasted' => $wasted, 'adjusted_in' => $r['adj_in'], 'adjusted_out' => $r['adj_out'], 'purchased' => $r['purchase']] as $field => $q) {
                $v = $value($q, $r['material_id']);
                $out[$field.'_value'] = $v === null ? null : $this->money($v);
                $out['_'.$field] = $v ?? 0.0;
            }

            return $out;
        })->sortBy([fn ($a, $b) => $b['_sold_net'] <=> $a['_sold_net'], fn ($a, $b) => strcasecmp($a['name'], $b['name'])])->values();

        $totals = [];
        foreach (['sold_net', 'wasted', 'adjusted_in', 'adjusted_out', 'purchased'] as $field) {
            $totals[$field.'_value'] = $this->money($data->sum('_'.$field));
        }
        $top = fn (string $field) => $data->groupBy('material_id')
            ->map(fn ($g, $id) => ['material_id' => (int) $id, 'name' => $g->first()['name'], 'value' => $g->sum('_'.$field)])
            ->filter(fn ($t) => $t['value'] > 0)->sortByDesc('value')->take(10)
            ->map(fn ($t) => ['material_id' => $t['material_id'], 'name' => $t['name'], 'value' => $this->money($t['value'])])->values()->all();

        return [
            'data' => $data->map(fn ($r) => array_filter($r, fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY))->all(),
            'totals' => $totals,
            'trend' => $this->trend($base, $params['date_from'], $params['date_to'], $materials, $costs),
            'top_used' => $top('sold_net'),
            'top_wasted' => $top('wasted'),
        ];
    }

    /**
     * Tren harian menurut TANGGAL BISNIS (BusinessDay::dateFor, termasuk jam tutup buku). Baris konsumsi dimuat lalu
     * dikelompokkan di PHP karena tanggal bisnis tidak tersimpan di pergerakan.
     */
    private function trend(\Closure $base, string $from, string $to, Collection $materials, array $costs): array
    {
        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'sold' => 0.0, 'wasted' => 0.0];
        }
        $base()->whereIn('type', self::CONSUMPTION)->whereIn('material_id', $materials->keys())
            ->get(['material_id', 'type', 'qty_change', 'created_at'])
            ->each(function ($m) use (&$days, $costs) {
                $date = BusinessDay::dateFor($m->created_at);
                $v = -(float) $m->qty_change * ($costs[$m->material_id] ?? 0);
                if (isset($days[$date])) {
                    $days[$date][$m->type === 'waste' ? 'wasted' : 'sold'] += $v;
                }
            });

        return array_values(array_map(fn ($d) => ['date' => $d['date'], 'sold_net_value' => $this->money($d['sold']), 'wasted_value' => $this->money($d['wasted'])], $days));
    }

    /**
     * 088 US4 — menu yang memakai satu bahan di periode: pergerakan sale/return berujukan baris pesanan → produk; pilihan
     * = id pilihan di snapshot baris ∩ pilihan yang memetakan bahan ini. Baris yang sudah dihapus dilewati.
     *
     * @return list<array<string, mixed>>
     */
    public function usageByMenu(Material $material, string $from, string $to, ?array $outletIds = null): array
    {
        $moves = $this->scoped(MaterialStockMovement::query(), $outletIds)
            ->where('material_id', $material->id)->whereIn('type', ['sale', 'return'])->where('reference_type', 'order_item')
            ->where('created_at', '>=', BusinessDay::bounds($from)[0])->where('created_at', '<', BusinessDay::bounds($to)[1])
            ->get(['reference_id', 'qty_change']);
        $lines = \App\Models\OrderItem::query()->whereIn('id', $moves->pluck('reference_id')->filter()->unique())
            ->with(['variant' => fn ($q) => $q->withTrashed(), 'variant.product' => fn ($q) => $q->withTrashed()])->get()->keyBy('id');
        $optionNames = \App\Models\ModifierOptionMaterial::query()->where('material_id', $material->id)->with('option:id,name')->get()
            ->mapWithKeys(fn ($m) => [$m->modifier_option_id => $m->option?->name]);

        $byProduct = [];
        foreach ($moves as $mv) {
            $line = $lines->get($mv->reference_id);
            $product = $line?->variant?->product;
            if ($product === null) {
                continue;
            }
            $byProduct[$product->id] ??= ['product_id' => $product->id, 'product' => $product->name, 'options' => [], 'qty' => 0.0];
            $byProduct[$product->id]['qty'] += -(float) $mv->qty_change;
            foreach ($line->modifiers ?? [] as $snap) {
                if (isset($optionNames[$snap['option_id'] ?? null])) {
                    $byProduct[$product->id]['options'][] = $optionNames[$snap['option_id']];
                }
            }
        }

        return collect($byProduct)->filter(fn ($p) => $p['qty'] > 0.0005)->sortByDesc('qty')
            ->map(fn ($p) => ['product_id' => $p['product_id'], 'product' => $p['product'], 'options' => array_values(array_unique($p['options'])), 'qty' => $this->qty($p['qty'])])
            ->values()->all();
    }

    /** @return array{0: string, 1: string} [tanggal bisnis awal jendela, hari ini] */
    /**
     * Outlet yang berlaku (OutletFilter) DIPOTONG lagi oleh saringan outlet halaman. `$outletIds` sudah melewati
     * `OutletContext::narrowedOrNull` di pengendali, jadi hanya bisa menyempitkan (Konstitusi IV); `[]` → hasil kosong.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @param  list<int>|null  $outletIds
     * @return T
     */
    private function scoped($query, ?array $outletIds)
    {
        $query = OutletFilter::apply($query, 'outlet_id');

        return $outletIds === null ? $query : $query->whereIn('outlet_id', $outletIds);
    }

    private function windowDates(int $window): array
    {
        $today = BusinessDay::today();

        return [CarbonImmutable::parse($today, BusinessDay::timezone())->subDays(max(1, $window) - 1)->toDateString(), $today];
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function summarize(Collection $rows): array
    {
        $counts = array_fill_keys(array_keys(self::STATUS_RANK), 0);
        foreach ($rows as $r) {
            $counts[$r['status']]++;
        }
        $values = $rows->pluck('_cost')->filter(fn ($c) => $c !== null);

        return $counts + [
            'at_risk_products' => $rows->pluck('at_risk_products')->flatten(1)->pluck('product_id')->unique()->count(),
            'stock_value' => $values->isEmpty() ? null : $this->money($rows->filter(fn ($r) => $r['_cost'] !== null)->sum(fn ($r) => $r['_on_hand'] * $r['_cost'])),
            'suggestion_cost' => $this->money($rows->sum(fn ($r) => (float) ($r['suggestion']['cost'] ?? 0))),
        ];
    }

    /**
     * Biaya satuan per bahan dengan aturan yang SAMA dengan RecipeService::unitCost / Material::referencePrice
     * (harga vendor pilihan dulu, lalu termurah, lalu id), dimuat dalam satu kueri.
     *
     * @param  Collection<int, Material>  $materials
     * @return array<int, float|null>
     */
    private function unitCosts(Collection $materials, Collection $reference): array
    {
        $out = [];
        foreach ($materials as $id => $m) {
            $price = $reference->get($id)?->price;
            $out[$id] = $price !== null ? (float) $price
                : ($m->kind === 'semi_finished' && $m->standard_cost !== null ? (float) $m->standard_cost : null);
        }

        return $out;
    }

    /** @return Collection<int, VendorMaterialPrice> materialId => harga rujukan (dengan vendor) */
    public function referencePrices(Collection $materialIds): Collection
    {
        return VendorMaterialPrice::query()->with('vendor')->whereIn('material_id', $materialIds)
            ->orderByDesc('is_preferred')->orderBy('price')->orderBy('id')->get()
            ->unique('material_id')->keyBy('material_id');
    }

    /** Bahan yang ada di resep/BOM tetapi tidak di pemetaan modifier mana pun: pemakaiannya tidak tercatat (D3). */
    private function usageUntracked(Collection $materialIds): Collection
    {
        if ($materialIds->isEmpty()) {
            return collect();
        }
        // Kueri mentah: mode data difilter eksplisit (CLAUDE.md 003); baris resep lewat `recipes` yang ber-mode.
        $mode = \App\Support\ModeGate::current();
        $inRecipeOrBom = DB::table('recipe_lines')->join('recipes', 'recipes.id', '=', 'recipe_lines.recipe_id')
            ->where('recipes.data_mode', $mode)->whereIn('recipe_lines.material_id', $materialIds)->pluck('recipe_lines.material_id')
            ->merge(DB::table('product_variant_bom_lines')->where('data_mode', $mode)->whereIn('material_id', $materialIds)->pluck('material_id'));
        $mapped = DB::table('modifier_option_materials')->where('data_mode', $mode)->whereIn('material_id', $materialIds)->pluck('material_id')->flip();

        return $inRecipeOrBom->unique()->reject(fn ($id) => $mapped->has($id))->flip();
    }

    private function qty(float $v): string
    {
        return number_format($v, 3, '.', '');
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
