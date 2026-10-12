<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\ModeGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * 042-dashboard-revamp — daftar "Perlu tindakan": hal yang butuh keputusan orang SEKARANG.
 *
 * Dihitung saat dibaca, tidak ada yang disimpan (tidak ada status dibaca/belum, tidak ada yang bisa
 * melenceng), memakai definisi yang sama dengan tempat lain yang menghitung kondisi itu (research.md D6).
 * Semua butir menggambarkan HARI BISNIS INI, bukan periode yang dipilih di Dashboard.
 *
 * Setiap jenis SELALU hadir; bila tidak ada yang perlu dilakukan, `severity` = `ok` dan `count` = 0
 * sehingga layar menampilkan keadaan "aman" alih-alih butirnya menghilang. `link` null bila pemanggil
 * tidak berhak membuka halaman tujuannya (hidden, not disabled — Konstitusi III).
 */
class AttentionService
{
    private const CACHE_SECONDS = 30;

    public const DEFAULT_TOLERANCE = '5000.00';

    /** route frontend => menu_key yang menggerbangnya (cermin router/index.js; server tetap penegak sebenarnya). */
    private const ROUTE_MENU = ['sales' => 'sales', 'stock' => 'stock', 'activity-log' => 'reports', 'session' => 'session'];

    public function items(User $user): array
    {
        $today = BusinessDay::today();
        // Cache per mode+hari+zona+outlet yang berlaku (angka mengikuti outlet pengguna); tautan dihitung per pemanggil di luar cache.
        $key = implode(':', ['dashboard.attention', ModeGate::current(), $today, BusinessDay::timezone(), BusinessDay::cutoff(), $this->tolerance(), 'outlets='.\App\Support\OutletFilter::cacheKey()]);

        $data = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->compute($today));

        $items = array_map(function (array $item) use ($user) {
            $link = $item['link'] ?? null;
            if ($link !== null && ! $user->canAccessMenu(self::ROUTE_MENU[$link['route']] ?? $link['route'])) {
                $link = null;
            }
            $item['link'] = $link;

            return $item;
        }, $data['items']);

        return [
            'computed_at' => $data['computed_at'],
            'business_date' => $today,
            'count' => collect($items)->where('severity', '!=', 'ok')->count(),
            'items' => $items,
        ];
    }

    private function compute(string $today): array
    {
        [$start, $end] = BusinessDay::bounds($today);

        return [
            'computed_at' => CarbonImmutable::now()->setTimezone(BusinessDay::timezone())->toIso8601String(),
            'items' => [
                $this->paymentsPending(),
                $this->voidAfterFire($start, $end),
                $this->cashDifference($start, $end),
                $this->lowStock(),
            ],
        ];
    }

    private function tolerance(): string
    {
        $v = Setting::get('cash_difference_tolerance', self::DEFAULT_TOLERANCE);

        return is_numeric($v) && (float) $v >= 0 ? number_format((float) $v, 2, '.', '') : self::DEFAULT_TOLERANCE;
    }

    private function item(string $kind, int $count, ?string $amount, array $meta, ?array $link, string $warnSeverity = 'warn', array $extra = []): array
    {
        return array_merge([
            'kind' => $kind,
            'severity' => $count > 0 ? $warnSeverity : 'ok',
            'count' => $count,
            'amount' => $amount,
            'meta' => $meta,
            'link' => $link,
        ], $extra);
    }

    /**
     * Order POS/tab yang punya pembayaran non-tunai menunggu verifikasi. Yang dihitung ORDER, bukan baris pembayaran, supaya jumlahnya sama dengan baris
     * pada halaman tujuan (filter "perlu verifikasi" di Sales: status terburuk = pending, jadi order yang
     * punya pembayaran ditolak tidak ikut). Nilai = jumlah pembayaran yang menunggu itu.
     */
    private function paymentsPending(): array
    {
        $pendingNonCash = fn ($q) => $q->where('verification', 'pending')->where('method', '!=', 'cash');

        $orders = Order::query()
            ->where('status', '!=', Order::STATUS_VOIDED)
            ->whereHas('payments', $pendingNonCash)
            ->whereDoesntHave('payments', fn ($q) => $q->where('verification', 'rejected'))
            ->with(['payments' => $pendingNonCash])
            ->get(['id']);

        $payments = $orders->flatMap->payments;

        return $this->item(
            'payments_pending',
            $orders->count(),
            number_format((float) $payments->sum('amount'), 2, '.', ''),
            ['by_method' => $payments->countBy('method')->all()],
            ['route' => 'sales', 'query' => ['pstate' => 'pending']],
        );
    }

    /** Item yang dibatalkan SETELAH tiket dikirim ke dapur/bar, hari ini. Nilai = qty × harga snapshot. */
    private function voidAfterFire(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = KitchenTicketItem::query()
            ->where('status', KitchenTicketItem::CANCELLED)
            ->where('cancelled_at', '>=', $start->utc())
            ->where('cancelled_at', '<', $end->utc())
            ->get(['id', 'qty', 'unit_price']);

        // Dihitung per BARIS (satu baris = satu item pesanan yang dibatalkan), bukan per porsi, supaya
        // jumlahnya sama dengan baris `item_cancelled` di log aktivitas yang dituju tautannya. Item yang
        // ikut batal karena seluruh tab di-void tidak punya baris log per item (hanya `tab_voided`) —
        // keterbatasan yang dicatat di spec, bukan disembunyikan.
        $units = $rows->count();
        $amount = $rows->whereNotNull('unit_price')->sum(fn ($r) => (int) $r->qty * (float) $r->unit_price);
        $unknown = $rows->whereNull('unit_price')->count();

        $gross = (float) (SalesFigures::totals(SalesFigures::leanBase(['date_from' => BusinessDay::today(), 'date_to' => BusinessDay::today()]))->gross_sales ?? 0);

        return $this->item(
            'void_after_fire',
            $units,
            number_format($amount, 2, '.', ''),
            ['share_of_gross_pct' => $gross > 0 ? round($amount / $gross * 100, 1) : null],
            ['route' => 'activity-log', 'query' => ['action' => 'item_cancelled', 'date' => BusinessDay::today()]],
            'warn',
            ['amount_unknown_count' => $unknown],
        );
    }

    /** Shift yang ditutup hari ini dengan selisih kas di luar toleransi (belum ada alur persetujuan). */
    private function cashDifference(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tolerance = $this->tolerance();

        $rows = CashierSession::query()
            ->where('status', 'closed')
            ->where('closed_at', '>=', $start->utc())
            ->where('closed_at', '<', $end->utc())
            ->whereNotNull('cash_difference')
            ->whereRaw('ABS(cash_difference) > ?', [$tolerance])
            ->get(['id', 'cash_difference']);

        $worst = $rows->sortByDesc(fn ($r) => abs((float) $r->cash_difference))->first();

        return $this->item(
            'cash_difference',
            $rows->count(),
            number_format((float) $rows->sum('cash_difference'), 2, '.', ''),
            ['tolerance' => $tolerance, 'session_id' => $worst?->id],
            ['route' => 'session', 'query' => $worst ? ['id' => $worst->id] : []],
            'critical',
        );
    }

    private function lowStock(): array
    {
        return $this->item('low_stock', LowStockHistory::current(), null, [], ['route' => 'stock', 'query' => ['low' => 1]]);
    }
}
