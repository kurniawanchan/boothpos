<?php

namespace App\Services;

use App\Support\ModeGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 054 (US11) — penjualan per METODE pembayaran (dan kanal) beserta daftar transaksinya.
 *
 * Definisi "jumlah" HARUS sama dengan halaman Sales supaya total di sini bisa dicocokkan dengan layar itu:
 *  - hanya penjualan POS yang selesai (batal tidak ikut), pembayaran `rejected` tidak ikut;
 *  - dikelompokkan menurut waktu PENJUALAN (`orders.created_at`), seperti filter tanggal Sales — pelunasan
 *    susulan atas penjualan lama dihitung pada hari penjualan itu;
 *  - TUNAI = uang diterima dikurangi kembalian PER PENJUALAN (tak pernah negatif): `cash_amount` di Sales;
 *  - split payment: tiap metode hanya menghitung porsinya, penjualannya muncul di tiap metode yang dipakai.
 * Query tangan tidak mengikuti scope DEMO/LIVE, jadi `data_mode` disaring eksplisit.
 */
class SalesByPaymentMethodReport
{
    /** @param  array{event_id?:mixed,date_from?:mixed,date_to?:mixed}  $filters */
    public function build(array $filters): array
    {
        // --- non-tunai: per (metode, kanal)
        $channelRows = $this->payments($filters)
            ->where('payments.method', '!=', 'cash')
            ->leftJoin('payment_channels', 'payment_channels.id', '=', 'payments.channel_id')
            ->selectRaw('payments.method, payments.channel_id, payment_channels.provider as provider')
            ->selectRaw('COUNT(DISTINCT orders.id) as transaction_count, SUM(payments.amount) as amount')
            ->selectRaw("SUM(CASE WHEN payments.verification = 'verified' THEN payments.amount ELSE 0 END) as verified_amount")
            ->groupBy('payments.method', 'payments.channel_id', 'payment_channels.provider')
            ->get();

        // --- tunai: per PENJUALAN dulu (uang diterima − kembalian, min 0), baru dijumlahkan
        $perOrderCash = $this->payments($filters)
            ->where('payments.method', 'cash')
            ->selectRaw('orders.id as order_id, GREATEST(SUM(payments.amount) - orders.change_amount, 0) as net')
            ->groupBy('orders.id', 'orders.change_amount')
            ->get();

        $rows = collect();
        if ($perOrderCash->isNotEmpty()) {
            $net = (float) $perOrderCash->sum('net');
            // Tunai dicatat `verified` sejak diterima (Payment/PaymentRecorder), jadi seluruhnya terverifikasi.
            $rows->push($this->row('cash', null, 'cash', $perOrderCash->count(), $net, $net));
        }
        foreach ($channelRows as $r) {
            $rows->push($this->row($r->method, $r->channel_id ? (int) $r->channel_id : null, $r->provider ?? $r->method, (int) $r->transaction_count, (float) $r->amount, (float) $r->verified_amount));
        }

        $rows = $rows->sortByDesc(fn ($r) => (float) $r['amount'])->values();

        $distinctOrders = (int) $this->payments($filters)->distinct()->count('orders.id');

        return [
            'totals' => [
                'transaction_count' => $distinctOrders,
                'amount' => $this->money($rows->sum(fn ($r) => (float) $r['amount'])),
                'verified_amount' => $this->money($rows->sum(fn ($r) => (float) $r['verified_amount'])),
                'unverified_amount' => $this->money($rows->sum(fn ($r) => (float) $r['unverified_amount'])),
            ],
            'data' => $rows->all(),
        ];
    }

    /**
     * Penjualan yang dibayar dengan satu metode (dan kanal bila diberikan); `amount` = porsi metode itu
     * (tunai: bersih dari kembalian), `verification` = yang TERBURUK di antara pembayaran metode itu.
     */
    public function transactions(string $method, ?int $channelId, array $filters, int $page, int $perPage): array
    {
        $base = $this->payments($filters)->where('payments.method', $method)
            ->when($method !== 'cash' && $channelId !== null, fn ($q) => $q->where('payments.channel_id', $channelId));

        $total = (int) (clone $base)->distinct()->count('orders.id');

        $rows = $base
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->selectRaw('orders.id as order_id, orders.order_number, orders.created_at, users.name as cashier_name, orders.change_amount')
            ->selectRaw('SUM(payments.amount) as gross')
            ->selectRaw("MAX(CASE payments.verification WHEN 'pending' THEN 2 WHEN 'verified' THEN 1 ELSE 0 END) as worst")
            ->groupBy('orders.id', 'orders.order_number', 'orders.created_at', 'users.name', 'orders.change_amount')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($r) => [
                'order_id' => (int) $r->order_id,
                'order_number' => $r->order_number,
                'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
                'cashier_name' => $r->cashier_name,
                'amount' => $this->money($method === 'cash' ? max(0, (float) $r->gross - (float) $r->change_amount) : (float) $r->gross),
                'verification' => (int) $r->worst === 2 ? 'pending' : 'verified',
            ])->all();

        return [
            'data' => $rows,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /** Pembayaran yang dihitung: penjualan selesai, pembayaran tidak ditolak, mode data aktif, filter event/tanggal. */
    private function payments(array $filters)
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.status', 'completed')
            ->where('payments.verification', '!=', 'rejected')
            ->where('payments.data_mode', ModeGate::current())
            ->where('orders.data_mode', ModeGate::current())
            ->when(! empty($filters['event_id']), fn ($q) => $q->where('orders.event_id', (int) $filters['event_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('orders.created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('orders.created_at', '<=', $filters['date_to']));
    }

    private function row(string $method, ?int $channelId, string $label, int $count, float $amount, float $verified): array
    {
        return [
            'method' => $method,
            'channel_id' => $channelId,
            'label' => $label,
            'transaction_count' => $count,
            'amount' => $this->money($amount),
            'verified_amount' => $this->money($verified),
            'unverified_amount' => $this->money($amount - $verified),
        ];
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
