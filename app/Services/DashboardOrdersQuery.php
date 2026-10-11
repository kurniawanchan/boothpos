<?php

namespace App\Services;

use App\Models\Order;
use App\Support\BusinessDay;
use App\Support\PaymentSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 042-dashboard-revamp — tabel "Order terbaru": tab dengan jumlah, pencarian, filter, baris, dan
 * baris untuk ekspor CSV, dari SATU definisi.
 *
 * Tab (research.md D7) — tiap order tepat satu, dievaluasi berurutan:
 *   voided → cancelled · open → open · completed & belum lunas → partial · completed & lunas → paid.
 * Status pembayaran turunan dihitung di SQL oleh fragmen di PaymentSummary (satu rumus, diuji paritas
 * dengan PaymentSummary::for()), BUKAN dari `orders.paid_amount` yang menyimpan uang diserahkan
 * (termasuk kembalian).
 *
 * "Pre-bill dicetak" dan "Ditutup" ada di desain tetapi tidak ada datanya di produk ini → tidak
 * dirender (`hidden_tabs`), bukan tab yang selalu nol.
 */
class DashboardOrdersQuery
{
    public const TABS = ['all', 'open', 'partial', 'paid', 'cancelled'];

    public const HIDDEN_TABS = ['bill', 'closed'];

    private const SORTS = [
        'created_at' => ['orders.created_at', 'asc'], '-created_at' => ['orders.created_at', 'desc'],
        'total_amount' => ['orders.total_amount', 'asc'], '-total_amount' => ['orders.total_amount', 'desc'],
        'order_number' => ['orders.order_number', 'asc'], '-order_number' => ['orders.order_number', 'desc'],
    ];

    /** Rentang tanggal bisnis bawaan sebuah periode Dashboard. */
    public static function rangeFor(string $period): array
    {
        $to = BusinessDay::today();
        $days = ['today' => 1, '7d' => 7, '30d' => 30][$period] ?? 1;

        return [CarbonImmutable::parse($to)->subDays($days - 1)->toDateString(), $to];
    }

    /** Tab (SQL) — dipakai untuk menghitung dan menyaring. */
    private function tabSql(): string
    {
        $remaining = PaymentSummary::orderRemainingSql('orders');

        return "CASE WHEN orders.status = 'voided' THEN 'cancelled' WHEN orders.status = 'open' THEN 'open' WHEN {$remaining} > 0 THEN 'partial' ELSE 'paid' END";
    }

    /** Status pembayaran (SQL): unpaid | partial | pending | paid. */
    private function paymentStateSql(): string
    {
        $remaining = PaymentSummary::orderRemainingSql('orders');
        $count = PaymentSummary::orderPaymentCountSql('orders');
        $pending = PaymentSummary::orderHasPendingNonCashSql('orders');

        return "CASE WHEN {$remaining} > 0 AND {$count} = 0 THEN 'unpaid' WHEN {$remaining} > 0 THEN 'partial' WHEN {$pending} THEN 'pending' ELSE 'paid' END";
    }

    /** Query dasar tanpa tab: mode data (lewat scope), rentang tanggal bisnis, pencarian, dan filter. */
    private function base(array $f): Builder
    {
        [$defaultFrom, $defaultTo] = self::rangeFor($f['period'] ?? 'today');
        $from = ($f['from'] ?? null) ?: $defaultFrom;
        $to = ($f['to'] ?? null) ?: $defaultTo;

        $q = Order::query()->whereBetween('orders.business_date', [$from, $to]);

        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w
                ->where('orders.order_number', 'like', $like)
                ->orWhereHas('table', fn ($t) => $t->where('name', 'like', $like))
                ->orWhereHas('items', fn ($i) => $i->where('name_snapshot', 'like', $like)));
        }

        if (! empty($f['type'])) {
            $q->whereIn('orders.service_type', (array) $f['type']);
        }

        if (! empty($f['method'])) {
            $q->whereHas('payments', fn ($p) => $p->whereIn('method', (array) $f['method'])->where('verification', '!=', 'rejected'));
        }

        if (! empty($f['pstat'])) {
            $q->whereRaw('('.$this->paymentStateSql().') in ('.implode(',', array_fill(0, count((array) $f['pstat']), '?')).')', array_values((array) $f['pstat']));
        }

        return $q;
    }

    /** @return array<string,int> jumlah per tab dengan SEMUA filter kecuali tab (+ 'all'). */
    public function counts(array $f): array
    {
        // Tabel turunan: MySQL (only_full_group_by) tidak mengenali CASE berisi subquery di SELECT sebagai
        // ekspresi yang sama dengan di GROUP BY, jadi tab dihitung dulu lalu dikelompokkan.
        $inner = $this->base($f)->reorder()->toBase()->selectRaw($this->tabSql().' as tab');
        $rows = DB::query()->fromSub($inner, 't')->selectRaw('t.tab, COUNT(*) as n')->groupBy('t.tab')->pluck('n', 'tab');

        $counts = ['all' => (int) $rows->sum()];
        foreach (['open', 'partial', 'paid', 'cancelled'] as $tab) {
            $counts[$tab] = (int) ($rows[$tab] ?? 0);
        }

        return $counts;
    }

    public function page(array $f): LengthAwarePaginator
    {
        $query = $this->filtered($f);
        [$col, $dir] = self::SORTS[$f['sort'] ?? '-created_at'] ?? self::SORTS['-created_at'];

        $paginator = $query->orderBy($col, $dir)->orderBy('orders.id', $dir)
            ->paginate((int) ($f['per_page'] ?? 25), ['orders.*'], 'page', (int) ($f['page'] ?? 1));

        $paginator->setCollection($this->present($paginator->getCollection()));

        return $paginator;
    }

    /** Semua baris untuk ekspor (tanpa halaman). */
    public function all(array $f): Collection
    {
        [$col, $dir] = self::SORTS[$f['sort'] ?? '-created_at'] ?? self::SORTS['-created_at'];

        return $this->present($this->filtered($f)->orderBy($col, $dir)->orderBy('orders.id', $dir)->get(['orders.*']));
    }

    private function filtered(array $f): Builder
    {
        $q = $this->base($f);
        $tab = $f['tab'] ?? 'all';
        if ($tab !== 'all' && in_array($tab, self::TABS, true)) {
            $q->whereRaw('('.$this->tabSql().') = ?', [$tab]);
        }

        return $q;
    }

    /** Bentuk baris — relasi dimuat per HALAMAN (satu query tiap relasi), bukan per baris. */
    private function present(Collection $orders): Collection
    {
        $orders->load(['table', 'items:id,order_id,name_snapshot,qty', 'payments:id,order_id,method,amount,verification']);
        $tz = BusinessDay::timezone();
        $states = $this->statesFor($orders->pluck('id')->all());

        return $orders->map(function (Order $o) use ($tz, $states) {
            $counted = $o->payments->where('verification', '!=', 'rejected');
            $paid = max(0.0, round((float) $counted->sum('amount') - (float) $o->change_amount, 2));
            $at = $o->completed_at ?? $o->created_at;

            return [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'type' => $o->service_type,
                'table' => $o->table?->name,
                'items_summary' => $this->itemsSummary($o),
                'status' => $o->status,
                'tab' => $states[$o->id]['tab'] ?? 'paid',
                'payment_state' => $states[$o->id]['payment_state'] ?? 'paid',
                'methods' => $counted->pluck('method')->unique()->values()->all(),
                'total_amount' => number_format((float) $o->total_amount, 2, '.', ''),
                'paid_amount' => number_format($paid, 2, '.', ''),
                'business_date' => $o->business_date,
                'at' => CarbonImmutable::instance($at)->setTimezone($tz)->toIso8601String(),
                'outlet_id' => null,
            ];
        });
    }

    /** @return array<int, array{tab: string, payment_state: string}> */
    private function statesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('orders')->whereIn('orders.id', $ids)
            ->selectRaw('orders.id, '.$this->tabSql().' as tab, '.$this->paymentStateSql().' as payment_state')
            ->get()->mapWithKeys(fn ($r) => [$r->id => ['tab' => $r->tab, 'payment_state' => $r->payment_state]])->all();
    }

    private function itemsSummary(Order $o): string
    {
        $items = $o->items;
        $first = $items->take(2)->map(fn ($i) => $i->name_snapshot.($i->qty > 1 ? ' ×'.$i->qty : ''))->implode(', ');
        $rest = $items->count() - 2;

        return $rest > 0 ? $first.' +'.$rest : $first;
    }
}
