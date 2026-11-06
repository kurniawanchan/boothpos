<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 054 (US12) — model baca untuk riwayat shift kasir: daftar, rincian (kas awal/penutupan), dan transaksi
 * dalam satu shift. Tidak menulis apa pun.
 *
 * `expectedCash()` adalah SATU-SATUNYA rumus kas yang diharapkan: dipakai `CashierSessionController::close()`
 * (yang menyimpannya saat shift ditutup) DAN rincian shift yang masih terbuka ("kas yang diharapkan sejauh
 * ini") — dua salinan rumus akan mulai berbeda diam-diam. Aturannya (lihat catatan di close()): tunai
 * terverifikasi yang DITERIMA di shift ini (`payments.session_id`), tanpa penjualan batal, dikurangi kembalian
 * penjualan shift ini; pembayaran pre-order (session_id NULL) di luar kas shift.
 *
 * Siapa yang boleh melihat: owner/admin semua shift; yang lain hanya shift miliknya sendiri — dijaga di sini
 * (`visibleTo`/`mayView`) supaya daftar dan rincian memakai aturan yang sama dengan `summary()`.
 */
class CashierSessionQuery
{
    public static function mayView(CashierSession $session, User $user): bool
    {
        return $user->isOwnerOrAdmin() || (int) $session->user_id === (int) $user->id;
    }

    public function expectedCash(CashierSession $session): float
    {
        $cash = Payment::where('session_id', $session->id)
            ->where('method', 'cash')
            ->where('verification', 'verified')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'voided'))
            ->sum('amount');

        $change = Order::where('session_id', $session->id)->where('status', '!=', 'voided')->sum('change_amount');

        return (float) $session->opening_cash + (float) $cash - (float) $change;
    }

    /**
     * @param  array{user_id?:mixed,event_id?:mixed,status?:mixed,date_from?:mixed,date_to?:mixed}  $filters
     * @return array{data:array,meta:array}
     */
    public function list(User $viewer, array $filters, int $page, int $perPage): array
    {
        $query = CashierSession::query()
            ->with(['user:id,name', 'event:id,name'])
            // 063 — jumlah pembukaan-kembali per shift dari SATU subquery (tanpa N+1).
            ->withCount('reopens')
            // Selain owner/admin dipaksa ke shift miliknya, APA PUN yang dikirim di query.
            ->when(! $viewer->isOwnerOrAdmin(), fn ($q) => $q->where('user_id', $viewer->id))
            ->when($viewer->isOwnerOrAdmin() && ! empty($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->when(! empty($filters['event_id']), fn ($q) => $q->where('event_id', (int) $filters['event_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('opened_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('opened_at', '<=', $filters['date_to']));

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('opened_at')->orderByDesc('id')->forPage($page, $perPage)->get()
            ->map(fn (CashierSession $s) => [
                'id' => $s->id,
                'cashier_id' => $s->user_id,
                'cashier_name' => $s->user?->name,
                'event_id' => $s->event_id,
                'event_name' => $s->event?->name,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'status' => $s->status,
                'opening_cash' => $this->money($s->opening_cash),
                'reopen_count' => (int) $s->reopens_count,
            ])->all();

        return [
            'data' => $rows,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /**
     * Rincian satu shift: angka kas awal/penutupan + ringkasan penjualan (sudah diotorisasi pemanggil).
     * 063: `can_reopen` dihitung SERVER (Owner + shift tertutup) agar SPA tak menurunkan izin dari peran,
     * dan `reopens` selalu array (kosong bila tak pernah dibuka kembali), terlama dulu.
     */
    public function detail(CashierSession $session, User $viewer): array
    {
        $session->loadMissing(['user:id,name', 'event:id,name', 'openingCashEntries.artist:id,name', 'reopens.reopenedBy:id,name']);

        $closed = $session->status === 'closed';
        $expected = $closed && $session->expected_cash !== null ? (float) $session->expected_cash : $this->expectedCash($session);
        $completed = $session->orders()->where('status', 'completed')->get();

        return [
            'id' => $session->id,
            'cashier_id' => $session->user_id,
            'cashier_name' => $session->user?->name,
            'event_id' => $session->event_id,
            'event_name' => $session->event?->name,
            'status' => $session->status,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'notes' => $session->notes,
            'opening_cash' => $this->money($session->opening_cash),
            // Shift tertutup memakai nilai yang DISIMPAN saat ditutup (rekonsiliasi tak berubah walau data
            // berubah kemudian); shift berjalan memakai rumus bersama — "diharapkan sejauh ini".
            'expected_cash' => $this->money($expected),
            'closing_cash' => $closed && $session->closing_cash !== null ? $this->money($session->closing_cash) : null,
            'cash_difference' => $closed && $session->cash_difference !== null ? $this->money($session->cash_difference) : null,
            'order_count' => $completed->count(),
            'total_sales' => $this->money($completed->sum('total_amount')),
            'by_method' => Payment::where('session_id', $session->id)
                ->whereHas('order', fn ($q) => $q->where('status', 'completed'))
                ->where('verification', 'verified')
                ->selectRaw('method, count(*) as count, sum(amount) as amount')
                ->groupBy('method')->get()
                ->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->count, 'amount' => $this->money($r->amount)])->all(),
            'opening_cash_entries' => $session->openingCashEntries->map(fn ($e) => [
                'artist_id' => $e->artist_id, 'artist_name' => $e->artist?->name, 'amount' => $this->money($e->amount),
            ])->all(),
            // Menutup shift yang masih terbuka dari riwayat (mis. shift milik kasir lain yang dibuka kembali): aturan
            // yang sama dengan close() — pemilik shift atau owner/admin — plus izin aksi session.edit dari gerbang rute.
            'can_close' => ! $closed && ($session->user_id === $viewer->id || $viewer->isOwnerOrAdmin()) && $viewer->canDo('session', 'edit'),
            'can_reopen' => $closed && (bool) $viewer->role?->isOwner(),
            'reopens' => $session->reopens->map(fn ($r) => [
                'id' => $r->id,
                'reopened_by_id' => $r->reopened_by,
                'reopened_by_name' => $r->reopenedBy?->name,
                'reopened_at' => $r->reopened_at?->toIso8601String(),
                'reason' => $r->reason,
                'previous_closed_at' => $r->previous_closed_at?->toIso8601String(),
                'previous_closing_cash' => $r->previous_closing_cash === null ? null : $this->money($r->previous_closing_cash),
                'previous_expected_cash' => $r->previous_expected_cash === null ? null : $this->money($r->previous_expected_cash),
                'previous_cash_difference' => $r->previous_cash_difference === null ? null : $this->money($r->previous_cash_difference),
            ])->values()->all(),
        ];
    }

    /**
     * Transaksi dalam satu shift: penjualan yang DIBUAT di shift ini (`kind: sale`) dan uang tunai/non-tunai
     * yang DITERIMA di shift ini untuk penjualan shift lain (`kind: payment_received`) — supaya aturan "kas
     * milik shift tempat uang diterima" terlihat, bukan hanya penjualan shift itu.
     *
     * @return array{data:array,meta:array}
     */
    public function transactions(CashierSession $session, int $page, int $perPage): array
    {
        $sales = Order::where('session_id', $session->id)->with('cashier:id,name')->get()->map(fn (Order $o) => [
            'kind' => 'sale',
            'order_id' => $o->id,
            'order_number' => $o->order_number,
            'created_at' => $o->created_at?->toIso8601String(),
            'cashier_name' => $o->cashier?->name,
            'amount' => $this->money($o->total_amount),
            'status' => $o->status,
            'method' => null,
        ]);

        $received = Payment::where('session_id', $session->id)
            ->whereHas('order', fn ($q) => $q->where('session_id', '!=', $session->id))
            ->with(['order:id,order_number,status,user_id', 'order.cashier:id,name'])->get()
            ->map(fn (Payment $p) => [
                'kind' => 'payment_received',
                'order_id' => $p->order_id,
                'order_number' => $p->order?->order_number,
                'created_at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                'cashier_name' => $p->order?->cashier?->name,
                'amount' => $this->money($p->amount),
                'status' => $p->order?->status,
                'method' => $p->method,
            ]);

        /** @var Collection $all */
        $all = $sales->concat($received)->sortByDesc(fn ($r) => $r['created_at'].sprintf('%012d', $r['order_id']))->values();
        $total = $all->count();

        return [
            'data' => $all->forPage($page, $perPage)->values()->all(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
