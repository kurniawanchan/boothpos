<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentSummary;
use Illuminate\Support\Collection;

/**
 * Daftar transaksi untuk halaman Sales DAN ekspornya — satu sumber, supaya berkas
 * ekspor tidak pernah berbeda dari yang tampil di layar.
 *
 * Satu baris = satu order POS (completed, atau juga voided bila diminta). Pre-order
 * SENGAJA tidak ada di sini: halaman Sales hanya menampilkan transaksi yang dibuat dari
 * POS; pre-order dikelola di layar Pre-orders.
 *
 * Halaman Sales terbuka untuk semua peran (kasir), jadi modal/margin HANYA ikut
 * bila pengguna boleh melihat laporan (canAccessMenu('reports'), sama dengan
 * ReportController::profit()) — bukan disaring di frontend.
 */
class SalesTransactionsService
{
    /**
     * @param  array{event_id?:mixed,date_from?:mixed,date_to?:mixed,include_voided?:mixed,keys?:?array}  $filters
     * @param  bool  $detail  true = tambahkan `items_text` (SEMUA baris item) — dipakai ekspor;
     *                         layar hanya butuh pratinjau 2 baris (`items_preview`).
     * @return array{transactions: Collection, sessions: Collection}
     */
    public function build(array $filters, User $user, bool $detail = false): array
    {
        $withMargin = $user->canAccessMenu('reports');

        // `keys` membatasi ke baris tertentu ("order:12"); kunci yang tak dikenal/di luar filter
        // diabaikan, bukan dibuatkan baris.
        $ids = isset($filters['keys'])
            ? collect($filters['keys'])->filter(fn ($k) => str_starts_with($k, 'order:'))
                ->map(fn ($k) => (int) substr($k, strlen('order:')))->values()->all()
            : null;

        $orders = ($ids === []) ? collect() : Order::query()
            ->with(['cashier', 'customer', 'items.artist', 'payments', 'session.user'])
            ->withCount('items')
            ->whereIn('status', filter_var($filters['include_voided'] ?? false, FILTER_VALIDATE_BOOLEAN) ? ['completed', 'voided'] : ['completed'])
            ->when(! empty($filters['event_id']), fn ($q) => $q->where('event_id', (int) $filters['event_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->get();

        $rows = $orders->map(fn (Order $o) => $this->orderRow($o, $withMargin, $detail))
            ->sortByDesc(fn (array $r) => $r['created_at'].sprintf('%012d', $r['id']))
            ->values();

        // 028-partial-split-payment (research Decision 7) — shift yang ditampilkan =
        // shift penjualan DAN shift tempat pembayaran susulannya diterima, supaya
        // shift yang hanya menerima pelunasan (tanpa penjualan di daftar) tetap
        // muncul. `cash_received` dihitung per shift dari SEMUA pembayarannya (bukan
        // hanya pesanan yang sedang disaring), lewat dua query teragregasi.
        $sessionIds = $orders->pluck('session_id')
            ->merge($orders->flatMap(fn (Order $o) => $o->payments->pluck('session_id')))
            ->filter()->unique()->values();

        $cashRows = Payment::whereIn('session_id', $sessionIds)
            ->where('method', 'cash')->where('verification', 'verified')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'voided'))
            ->selectRaw('session_id, SUM(amount) AS total')->groupBy('session_id')->pluck('total', 'session_id');
        $changeRows = Order::whereIn('session_id', $sessionIds)->where('status', '!=', 'voided')
            ->selectRaw('session_id, SUM(change_amount) AS total')->groupBy('session_id')->pluck('total', 'session_id');

        $sessions = CashierSession::with('user')->whereIn('id', $sessionIds)->get()
            ->sortByDesc('opened_at')
            ->map(fn ($s) => [
                'id' => $s->id,
                'cashier_name' => $s->user?->name,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'status' => $s->status,
                'opening_cash' => $this->money($s->opening_cash),
                'closing_cash' => $s->closing_cash === null ? null : $this->money($s->closing_cash),
                'expected_cash' => $s->expected_cash === null ? null : $this->money($s->expected_cash),
                'cash_received' => $this->money(max(0, (float) ($cashRows[$s->id] ?? 0) - (float) ($changeRows[$s->id] ?? 0))),
            ])->values();

        return ['transactions' => $rows, 'sessions' => $sessions];
    }

    private function orderRow(Order $order, bool $withMargin, bool $detail): array
    {
        $items = $order->items;
        $payments = $order->payments->where('verification', '!=', 'rejected');
        $summary = PaymentSummary::for($order, $order->payments);
        $cash = (float) $payments->where('method', 'cash')->sum('amount');
        $noncash = (float) $payments->where('method', '!=', 'cash')->sum('amount');
        $top = $items->sortByDesc(fn ($i) => (float) $i->line_total)->values();

        $row = ($detail ? ['items_text' => $items->map(fn ($i) => $i->name_snapshot.' ×'.$this->plain($i->qty))->implode('; ')] : []) + [
            'key' => "order:{$order->id}",
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'void_reason' => $order->void_reason,
            'created_at' => $order->created_at?->toIso8601String(),
            'cashier_id' => $order->user_id,
            'cashier_name' => $order->cashier?->name,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->name,
            // Popover detail pelanggan di Sales memakai data ini (FR-022).
            'customer_phone' => $order->customer?->phone,
            'customer_email' => $order->customer?->email,
            'item_count' => $order->items_count,               // jumlah BARIS item
            'unit_count' => (float) $items->sum('qty'),        // jumlah unit
            // Total diskon pembeli = diskon per item + diskon tingkat order (tidak dobel:
            // orders.subtotal sudah pasca-diskon-item, lihat OrderService).
            'discount_amount' => $this->money($items->sum('discount_amount') + (float) $order->discount_amount),
            'payment_methods' => $order->payments->pluck('method')->unique()->values(),
            'payment_state' => $this->paymentState($order->payments),
            'channel' => $order->channel,
            'artist_names' => $items->pluck('artist.name')->filter()->unique()->values(),
            'items_preview' => $top->take(2)->map(fn ($i) => ['name' => $i->name_snapshot, 'qty' => (float) $i->qty])->values(),
            'items_more' => max(0, $items->count() - 2),
            'total_amount' => $this->money($order->total_amount),
            // Tunai BERSIH: uang tunai yang diterima dikurangi kembalian.
            'cash_amount' => $this->money(max(0, $cash - (float) $order->change_amount)),
            'noncash_amount' => $this->money($noncash),
            // 028 — status pembayaran turunan (Unpaid/Partially Paid/Fully Paid), uang yang
            // sudah dibayar, dan sisa tagihan; `payment_state` (verifikasi) tetap ada.
            'paid_amount' => $summary['total_paid'],
            'balance_amount' => $summary['remaining'],
            'payment_status' => $summary['status'],
            'session_id' => $order->session_id,
        ];

        if ($withMargin) {
            $cost = (float) $order->total_cost;
            $revenue = (float) $order->total_amount;
            $row += [
                'cost_total' => $this->money($cost),
                'margin_amount' => $this->money($revenue - $cost),
                'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0,
            ];
        }

        return $row;
    }

    /** Verifikasi terburuk di antara pembayaran: rejected > pending > verified; none bila belum ada. */
    private function paymentState(Collection $payments): string
    {
        return match (true) {
            $payments->contains('verification', 'rejected') => 'rejected',
            $payments->contains('verification', 'pending') => 'pending',
            $payments->isNotEmpty() => 'verified',
            default => 'none',
        };
    }

    /** 3.00 → "3", 2.50 → "2.5" (jumlah unit untuk teks). */
    private function plain(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
