<?php

namespace App\Services;

use App\Support\BusinessDay;
use App\Support\ModeGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * 042-dashboard-revamp — ringkasan Dashboard: empat angka utama, pembandingnya, dan deret tren.
 * Dihitung saat dibaca (tidak ada yang disimpan), di-cache ≤ 30 detik (Konstitusi V) dengan kunci yang
 * memuat SEMUA masukan yang mengubah isinya — mode data, periode, hari bisnis, zona waktu, cut-off —
 * dan selalu mengembalikan `computed_at` (kapan angka ini dihitung).
 *
 * Definisi (research.md D4/D5):
 *  - Total penjualan = penjualan bersih POS dari SalesFigures (sama dengan kartu halaman Sales).
 *  - Total order = order SEMUA status pada rentang itu; rata-rata = bersih ÷ order SELESAI.
 *  - "Hari ini": nilai kumulatif sejak awal hari bisnis sampai sekarang, dibanding kumulatif kemarin
 *    sampai waktu yang sama; deret per jam. "7 hari/30 hari": total rentang vs rentang sebelumnya
 *    yang sama panjang; deret per hari.
 *  - Garis putus-putus = deret pembanding; tidak ada proyeksi karangan.
 */
class DashboardService
{
    public const PERIODS = ['today', '7d', '30d'];

    private const CACHE_SECONDS = 30;

    public function summary(string $period): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'today';
        $key = implode(':', ['dashboard.summary', ModeGate::current(), $period, BusinessDay::today(), BusinessDay::timezone(), BusinessDay::cutoff(), 'outlets='.\App\Support\OutletFilter::cacheKey()]);

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->compute($period));
    }

    private function compute(string $period): array
    {
        $now = CarbonImmutable::now();
        $computedAt = $now->setTimezone(BusinessDay::timezone());
        $today = BusinessDay::today();

        $body = $period === 'today' ? $this->today($now, $today) : $this->ranged($period, $today);

        return array_merge([
            'computed_at' => $computedAt->toIso8601String(),
            'business_date' => $today,
            'period' => $period,
        ], $body);
    }

    // --- Hari ini ---------------------------------------------------------------------------

    private function today(CarbonImmutable $now, string $today): array
    {
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();
        [$start, $end] = BusinessDay::bounds($today);
        [$cmpStart, $cmpEnd] = BusinessDay::bounds($yesterday);

        $elapsed = max(0, min($now->getTimestamp(), $end->getTimestamp()) - $start->getTimestamp());
        $cmpUpTo = $cmpStart->addSeconds(min($elapsed, $cmpEnd->getTimestamp() - $cmpStart->getTimestamp()));

        $hours = (int) ceil(($end->getTimestamp() - $start->getTimestamp()) / 3600);
        $cmpHours = (int) ceil(($cmpEnd->getTimestamp() - $cmpStart->getTimestamp()) / 3600);
        $currentHours = min($hours, (int) floor($elapsed / 3600) + 1);   // jam berjalan ikut (parsial)

        // Jendela "sampai sekarang" setengah-terbuka di now+1 detik supaya order yang tercatat tepat pada detik ini
        // ikut terhitung (batas atas jendela selalu eksklusif). Tiap sisi hanya DUA query (selesai + semua status);
        // nilai "sampai jam yang sama" diturunkan dari baris yang sama, bukan query ulang.
        $cur = $this->hourly($start, $now->addSecond(), $hours);
        $cmp = $this->hourly($cmpStart, $cmpEnd, $cmpHours);

        $curValue = ['net' => end($cur['net']) ?: 0.0, 'orders' => end($cur['orders']) ?: 0, 'completed' => end($cur['completed']) ?: 0];
        $cmpValue = $this->upTo($cmp, $cmpStart, $cmpUpTo);

        $labels = fn (CarbonImmutable $s, int $n) => array_map(
            fn ($i) => $s->addHours($i)->setTimezone(BusinessDay::timezone())->format('H:00'), range(0, max(0, $n - 1)),
        );
        $curLabels = $labels($start, $hours);
        $cmpLabels = $labels($cmpStart, $cmpHours);

        // Satu panggilan riwayat stok untuk semua instan (pembanding "jam yang sama", jam-jam hari ini, jam-jam kemarin).
        $lowInstants = array_map(fn ($i) => $start->addHours($i + 1)->min($now), range(0, $currentHours - 1));
        $lowCmpInstants = array_map(fn ($i) => $cmpStart->addHours($i + 1)->min($cmpEnd), range(0, $cmpHours - 1));
        $lowAll = LowStockHistory::at(array_merge([$cmpUpTo], $lowInstants, $lowCmpInstants));
        $lowCmpAtSame = $lowAll[0];
        $lowCur = array_slice($lowAll, 1, count($lowInstants));
        $lowCmp = array_slice($lowAll, 1 + count($lowInstants));

        $curNet = array_slice($cur['net'], 0, $currentHours);

        return [
            'range' => ['from' => $today, 'to' => $today],
            'comparison' => ['basis' => 'same_time_yesterday', 'from' => $yesterday, 'to' => $yesterday, 'up_to' => $cmpUpTo->setTimezone(BusinessDay::timezone())->format('H:i')],
            'kpis' => [
                'net_sales' => $this->money($curValue['net'], $cmpValue['net'], $this->points($curLabels, $curNet, true), $this->points($cmpLabels, $cmp['net'], true)),
                'orders' => $this->count($curValue['orders'], $cmpValue['orders'], $this->points($curLabels, array_slice($cur['orders'], 0, $currentHours)), $this->points($cmpLabels, $cmp['orders'])),
                'avg_order_value' => $this->average($curValue, $cmpValue,
                    $this->avgPoints($curLabels, $curNet, array_slice($cur['completed'], 0, $currentHours)),
                    $this->avgPoints($cmpLabels, $cmp['net'], $cmp['completed'])),
                'low_stock' => $this->lowStock(LowStockHistory::current(), $lowCmpAtSame, $this->points($curLabels, $lowCur), $this->points($cmpLabels, $lowCmp)),
            ],
            // 048 / S4 — pendapatan periode: net (pra-pajak), diskon tingkat-order, pajak, dan grand total
            // (= net − diskon + pajak, sama dengan struk), angka yang sama dengan laporan penjualan. Hanya nilai
            // periode ini; deret pembanding tetap berbasis net.
            'revenue' => $this->revenue(
                $curValue['net'],
                array_sum(array_column($cur['rows']['sales'], 'discount')),
                array_sum(array_column($cur['rows']['sales'], 'tax')),
            ),
        ];
    }

    /** @return array{net_sales: string, order_discount_total: string, tax_total: string, grand_total: string} */
    private function revenue(float $net, float $orderDiscount, float $tax): array
    {
        return [
            'net_sales' => number_format($net, 2, '.', ''),
            'order_discount_total' => number_format($orderDiscount, 2, '.', ''),
            'tax_total' => number_format($tax, 2, '.', ''),
            'grand_total' => number_format(SalesFigures::grandTotal($net, $orderDiscount, $tax), 2, '.', ''),
        ];
    }

    /**
     * Deret kumulatif per jam dari `$start` sampai `$until` (dua query: order selesai + order semua status).
     * `completed` = jumlah order selesai kumulatif (pembagi rata-rata). `rows` disimpan agar nilai pada instan
     * mana pun di dalam jendela dapat diturunkan tanpa query ulang.
     *
     * @return array{net: list<float>, orders: list<int>, completed: list<int>, rows: array{sales: list<array{at:string,net:float}>, orders: list<string>}}
     */
    private function hourly(CarbonImmutable $start, CarbonImmutable $until, int $hours): array
    {
        $net = array_fill(0, $hours, 0.0);
        $completed = array_fill(0, $hours, 0);
        $orders = array_fill(0, $hours, 0);

        $sales = SalesFigures::completedNetBetween($start, $until);
        $instants = SalesFigures::orderInstantsBetween($start, $until);

        foreach ($sales as $row) {
            $i = $this->bucket($start, $row['at'], $hours);
            $net[$i] += $row['net'];
            $completed[$i]++;
        }
        foreach ($instants as $at) {
            $orders[$this->bucket($start, $at, $hours)]++;
        }

        for ($i = 1; $i < $hours; $i++) {
            $net[$i] += $net[$i - 1];
            $completed[$i] += $completed[$i - 1];
            $orders[$i] += $orders[$i - 1];
        }

        return ['net' => $net, 'orders' => $orders, 'completed' => $completed, 'rows' => ['sales' => $sales, 'orders' => $instants]];
    }

    /** @return array{net: float, orders: int, completed: int} jumlah pada [start, until) dari baris yang sudah dimuat */
    private function upTo(array $hourly, CarbonImmutable $start, CarbonImmutable $until): array
    {
        $limit = $until->utc()->format('Y-m-d H:i:s');
        $net = 0.0;
        $completed = 0;
        foreach ($hourly['rows']['sales'] as $row) {
            if ($row['at'] < $limit) {
                $net += $row['net'];
                $completed++;
            }
        }

        return ['net' => $net, 'orders' => count(array_filter($hourly['rows']['orders'], fn ($at) => $at < $limit)), 'completed' => $completed];
    }

    private function bucket(CarbonImmutable $start, string $utcAt, int $hours): int
    {
        $seconds = CarbonImmutable::parse($utcAt, 'UTC')->getTimestamp() - $start->getTimestamp();

        return max(0, min($hours - 1, (int) floor($seconds / 3600)));
    }

    // --- 7 hari / 30 hari -------------------------------------------------------------------

    private function ranged(string $period, string $today): array
    {
        $days = $period === '7d' ? 7 : 30;
        $to = $today;
        $from = CarbonImmutable::parse($today)->subDays($days - 1)->toDateString();
        $cmpTo = CarbonImmutable::parse($from)->subDay()->toDateString();
        $cmpFrom = CarbonImmutable::parse($cmpTo)->subDays($days - 1)->toDateString();

        $cur = $this->daily($from, $to, $days);
        $cmp = $this->daily($cmpFrom, $cmpTo, $days);

        $curTotal = ['net' => array_sum($cur['net']), 'orders' => array_sum($cur['orders']), 'completed' => array_sum($cur['completed'])];
        $cmpTotal = ['net' => array_sum($cmp['net']), 'orders' => array_sum($cmp['orders']), 'completed' => array_sum($cmp['completed'])];

        $curLabels = array_keys($cur['net']);
        $cmpLabels = array_keys($cmp['net']);

        // Stok menipis: akhir tiap hari bisnis (instan = awal hari berikutnya, dibatasi "sekarang").
        $now = CarbonImmutable::now();
        $endOf = fn (string $d) => BusinessDay::bounds($d)[1]->min($now);
        $lowAll = LowStockHistory::at(array_merge(array_map($endOf, $curLabels), array_map($endOf, $cmpLabels)));
        $lowCur = array_slice($lowAll, 0, count($curLabels));
        $lowCmp = array_slice($lowAll, count($curLabels));
        $lowCmpValue = $lowCmp === [] ? 0 : end($lowCmp);
        $adjustments = SalesFigures::orderAdjustmentsBetween($from, $to);

        return [
            'range' => ['from' => $from, 'to' => $to],
            'comparison' => ['basis' => 'previous_period', 'from' => $cmpFrom, 'to' => $cmpTo],
            'kpis' => [
                'net_sales' => $this->money($curTotal['net'], $cmpTotal['net'], $this->points($curLabels, array_values($cur['net']), true), $this->points($cmpLabels, array_values($cmp['net']), true)),
                'orders' => $this->count($curTotal['orders'], $cmpTotal['orders'], $this->points($curLabels, array_values($cur['orders'])), $this->points($cmpLabels, array_values($cmp['orders']))),
                'avg_order_value' => $this->average($curTotal, $cmpTotal,
                    $this->avgPoints($curLabels, array_values($cur['net']), array_values($cur['completed'])),
                    $this->avgPoints($cmpLabels, array_values($cmp['net']), array_values($cmp['completed']))),
                'low_stock' => $this->lowStock(LowStockHistory::current(), $lowCmpValue, $this->points($curLabels, $lowCur), $this->points($cmpLabels, $lowCmp)),
            ],
            'revenue' => $this->revenue($curTotal['net'], $adjustments->discount, $adjustments->tax),
        ];
    }

    /** @return array{net: array<string,float>, orders: array<string,int>, completed: array<string,int>} per tanggal, hari kosong = 0 */
    private function daily(string $from, string $to, int $days): array
    {
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = CarbonImmutable::parse($from)->addDays($i)->toDateString();
        }

        $netByDay = SalesFigures::netByDay($from, $to);
        $orderByDay = SalesFigures::orderCountByDay($from, $to);
        $completedByDay = $this->completedCountByDay($from, $to);

        $net = $orders = $completed = [];
        foreach ($dates as $d) {
            $net[$d] = (float) ($netByDay[$d] ?? 0);
            $orders[$d] = (int) ($orderByDay[$d] ?? 0);
            $completed[$d] = (int) ($completedByDay[$d] ?? 0);
        }

        return compact('net', 'orders', 'completed');
    }

    /** @return array<string,int> order selesai per tanggal bisnis (pembagi rata-rata) */
    private function completedCountByDay(string $from, string $to): array
    {
        return SalesFigures::leanBase(['date_from' => $from, 'date_to' => $to])
            ->selectRaw('orders.business_date as day, COUNT(DISTINCT orders.id) as n')
            ->groupBy('orders.business_date')
            ->pluck('n', 'day')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    // --- Bentuk keluaran --------------------------------------------------------------------

    /** @param list<string> $labels @param list<int|float> $values */
    private function points(array $labels, array $values, bool $money = false): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[] = ['t' => $labels[$i] ?? (string) $i, 'v' => $money ? number_format((float) $v, 2, '.', '') : $v];
        }

        return $out;
    }

    private function avgPoints(array $labels, array $net, array $completed): array
    {
        $out = [];
        foreach ($net as $i => $n) {
            $c = $completed[$i] ?? 0;
            $out[] = ['t' => $labels[$i] ?? (string) $i, 'v' => $c > 0 ? number_format($n / $c, 2, '.', '') : '0.00'];
        }

        return $out;
    }

    private function pct(float $cur, float $cmp): ?float
    {
        return $cmp > 0 ? round(($cur - $cmp) / $cmp * 100, 1) : null;
    }

    private function money(float $cur, float $cmp, array $series, array $comparisonSeries): array
    {
        return [
            'value' => number_format($cur, 2, '.', ''),
            'comparison' => number_format($cmp, 2, '.', ''),
            'change_pct' => $this->pct($cur, $cmp),
            'series' => $series,
            'comparison_series' => $comparisonSeries,
        ];
    }

    private function count(int $cur, int $cmp, array $series, array $comparisonSeries): array
    {
        return ['value' => $cur, 'comparison' => $cmp, 'change_pct' => $this->pct((float) $cur, (float) $cmp),
            'series' => $series, 'comparison_series' => $comparisonSeries];
    }

    /** Rata-rata nilai order = bersih ÷ order SELESAI; tanpa order selesai → null, bukan pembagian nol. */
    private function average(array $cur, array $cmp, array $series, array $comparisonSeries): array
    {
        $curAvg = $cur['completed'] > 0 ? $cur['net'] / $cur['completed'] : null;
        $cmpAvg = $cmp['completed'] > 0 ? $cmp['net'] / $cmp['completed'] : null;

        return [
            'value' => $curAvg === null ? null : number_format($curAvg, 2, '.', ''),
            'comparison' => $cmpAvg === null ? null : number_format($cmpAvg, 2, '.', ''),
            'change_pct' => ($curAvg !== null && $cmpAvg !== null) ? $this->pct($curAvg, $cmpAvg) : null,
            'series' => $series,
            'comparison_series' => $comparisonSeries,
        ];
    }

    private function lowStock(int $cur, int $cmp, array $series, array $comparisonSeries): array
    {
        return ['value' => $cur, 'comparison' => $cmp, 'change_abs' => $cur - $cmp, 'scope' => 'outlet',
            'series' => $series, 'comparison_series' => $comparisonSeries];
    }
}
