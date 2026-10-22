<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Support\BusinessDay;

/**
 * 048 / S2 — SATU perhitungan angka shift, dipakai `summary()`, X-report, dan penutupan (Z-report).
 *
 * Aturan yang sama dengan sebelumnya, dipindahkan ke sini supaya tidak ada rumus kedua:
 *  - tunai yang DITERIMA di shift = pembayaran tunai ter-verifikasi ber-`session_id` shift ini (uang
 *    diterima menurut shift tempat pembayaran masuk, research D7 fitur 028); order batal dikeluarkan;
 *  - kembalian dikurangkan di shift PENJUALANNYA (kembalian hanya terjadi saat checkout);
 *  - kas masuk menambah dan kas keluar mengurangi kas yang seharusnya ada di laci.
 *
 * Shift yang sudah ditutup memakai `expected_cash` yang TERSIMPAN, bukan dihitung ulang: pembayaran
 * susulan yang masuk setelah tutup tak boleh menggeser rekonsiliasi yang sudah dicatat.
 */
class ShiftReportService
{
    /** Kas yang seharusnya ada di laci. Satu-satunya tempat rumusnya. */
    public function expectedCash(CashierSession $session): float
    {
        if ($session->status === 'closed' && $session->expected_cash !== null) {
            return (float) $session->expected_cash;
        }

        return (float) $session->opening_cash
            + $this->cashReceived($session)
            - $this->changeGiven($session)
            + $this->movementTotal($session, CashMovement::IN)
            - $this->movementTotal($session, CashMovement::OUT);
    }

    /** Tunai yang diterima di shift ini (menurut `payments.session_id`), order batal dikeluarkan. */
    public function cashReceived(CashierSession $session): float
    {
        return (float) Payment::where('session_id', $session->id)
            ->where('method', 'cash')
            ->where('verification', 'verified')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'voided'))
            ->sum('amount');
    }

    /** Kembalian yang diberikan pada penjualan shift ini (di shift penjualannya), order batal dikeluarkan. */
    public function changeGiven(CashierSession $session): float
    {
        return (float) Order::where('session_id', $session->id)->where('status', '!=', 'voided')->sum('change_amount');
    }

    /**
     * Angka lengkap X-report dan salinan Z-report. Dihitung langsung, tidak disimpan. Semua uang
     * dalam bentuk string dua desimal (konvensi API), kecuali jumlah transaksi (integer).
     *
     * @return array<string, mixed>
     */
    public function figures(CashierSession $session): array
    {
        $completed = $session->orders()->where('status', Order::STATUS_COMPLETED)->get();
        $voided = $session->orders()->where('status', Order::STATUS_VOIDED)->get();

        $byMethod = Payment::where('session_id', $session->id)
            ->whereHas('order', fn ($q) => $q->where('status', Order::STATUS_COMPLETED))
            ->where('verification', 'verified')
            ->selectRaw('method, count(*) as count, sum(amount) as amount')
            ->groupBy('method')
            ->get();

        // Penjualan per jam (jam dinding toko, bukan UTC). Order tanpa `completed_at` (data lama) dilewati.
        $hourly = $completed
            ->filter(fn (Order $o) => $o->completed_at !== null)
            ->groupBy(fn (Order $o) => $o->completed_at->timezone(BusinessDay::timezone())->format('H'))
            ->sortKeys()
            // Kunci grup angka-string ("09") diubah PHP menjadi integer; kontrak API menjanjikan string.
            ->map(fn ($group, $hour) => [
                'hour' => (string) $hour,
                'order_count' => $group->count(),
                'total' => $this->money($group->sum('total_amount')),
            ])
            ->values()
            ->all();

        return [
            'session_id' => $session->id,
            // 064 — terminal tempat shift berjalan (null untuk shift SPA web / terminal yang sudah dihapus).
            'terminal_name' => $session->device?->name,
            'status' => $session->status,
            'opening_cash' => $this->money($session->opening_cash),
            'order_count' => $completed->count(),
            'total_sales' => $this->money($completed->sum('total_amount')),
            'void_count' => $voided->count(),
            'void_total' => $this->money($voided->sum('total_amount')),
            'change_given' => $this->money($this->changeGiven($session)),
            'cash_received' => $this->money($this->cashReceived($session)),
            'cash_in' => $this->money($this->movementTotal($session, CashMovement::IN)),
            'cash_out' => $this->money($this->movementTotal($session, CashMovement::OUT)),
            'expected_cash' => $this->money($this->expectedCash($session)),
            'by_method' => $byMethod->map(fn ($row) => [
                'method' => $row->method,
                'count' => (int) $row->count,
                'amount' => $this->money($row->amount),
            ])->values()->all(),
            'hourly' => $hourly,
        ];
    }

    /**
     * 065 — angka beberapa shift SEKALIGUS untuk daftar shift dan panel dashboard: satu kueri terkelompok per sumber
     * data (order, pembayaran, kas masuk/keluar), jadi jumlah kueri tidak naik bersama jumlah baris. Rumusnya SAMA
     * dengan {@see figures()} / {@see expectedCash()} (tunai terverifikasi dikurangi kembalian, ditambah kas masuk,
     * dikurangi kas keluar; order batal dikeluarkan). Shift tertutup memakai `expected_cash` yang tersimpan.
     *
     * @param  \Illuminate\Support\Collection<int, CashierSession>  $sessions
     * @return array<int, array<string, float|int>> dikunci id shift
     */
    public function figuresForMany($sessions): array
    {
        $ids = $sessions->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $orders = Order::withoutGlobalScopes()->whereIn('session_id', $ids)
            ->selectRaw("session_id,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END) AS completed_total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS void_count,
                SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END) AS void_total,
                SUM(CASE WHEN status != ? THEN change_amount ELSE 0 END) AS change_given", [Order::STATUS_COMPLETED, Order::STATUS_COMPLETED, Order::STATUS_VOIDED, Order::STATUS_VOIDED, Order::STATUS_VOIDED])
            ->groupBy('session_id')->get()->keyBy('session_id');

        // Tunai/non-tunai TERVERIFIKASI per shift tempat uang diterima (payments.session_id), order batal dikeluarkan.
        $payments = Payment::withoutGlobalScopes()->whereIn('session_id', $ids)
            ->where('verification', 'verified')
            ->whereHas('order', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', Order::STATUS_VOIDED))
            ->selectRaw("session_id, SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) AS cash, SUM(CASE WHEN method != 'cash' THEN amount ELSE 0 END) AS noncash")
            ->groupBy('session_id')->get()->keyBy('session_id');

        $moves = CashMovement::withoutGlobalScopes()->whereIn('session_id', $ids)
            ->selectRaw("session_id, SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) AS cash_in, SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) AS cash_out", [CashMovement::IN, CashMovement::OUT])
            ->groupBy('session_id')->get()->keyBy('session_id');

        $out = [];
        foreach ($sessions as $s) {
            $o = $orders->get($s->id);
            $p = $payments->get($s->id);
            $m = $moves->get($s->id);
            $cashReceived = (float) ($p->cash ?? 0);
            $change = (float) ($o->change_given ?? 0);
            $in = (float) ($m->cash_in ?? 0);
            $outAmount = (float) ($m->cash_out ?? 0);

            $out[$s->id] = [
                'order_count' => (int) ($o->completed_count ?? 0),
                'sales_total' => (float) ($o->completed_total ?? 0),
                'void_count' => (int) ($o->void_count ?? 0),
                'void_total' => (float) ($o->void_total ?? 0),
                'cash_received' => $cashReceived,
                'change_given' => $change,
                'cash_net' => $cashReceived - $change,
                'noncash_total' => (float) ($p->noncash ?? 0),
                'cash_in' => $in,
                'cash_out' => $outAmount,
                'expected_cash' => $s->status === 'closed' && $s->expected_cash !== null
                    ? (float) $s->expected_cash
                    : (float) $s->opening_cash + $cashReceived - $change + $in - $outAmount,
            ];
        }

        return $out;
    }

    public function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function movementTotal(CashierSession $session, string $direction): float
    {
        // Sudah dikunci lewat sesinya (yang sudah lolos scope); tidak bergantung konteks outlet permintaan.
        return (float) CashMovement::withoutGlobalScopes()
            ->where('session_id', $session->id)
            ->where('direction', $direction)
            ->sum('amount');
    }
}
