<?php

namespace App\Services;

use App\Models\ArtistSettlement;
use App\Models\Event;
use App\Support\ModeGate;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung ULANG (bukan membaca angka lama) hasil penjualan per artist
 * dari order_items setiap dipanggil. artist_settlements berfungsi sebagai
 * cache + tempat mencatat status pembayaran ke artist, BUKAN sumber
 * kebenaran nilai penjualan — sumber kebenarannya selalu order_items.
 */
class SettlementService
{
    /**
     * Mengembalikan rincian POS/pre-order (salesBreakdownForEvent) yang
     * dipakai untuk menulis total — pemanggil yang butuh kolom POS vs
     * pre-order memakai hasil ini, bukan menghitung ulang, supaya angka
     * di layar berasal dari satu snapshot yang sama dengan total tersimpan.
     */
    public function recalculateForEvent(Event $event): \Illuminate\Support\Collection
    {
        // FIX (ditemukan lewat test_settlement_recalculates_live_after_a_void):
        // reset dulu SEMUA baris settlement event ini ke nol. Tanpa ini,
        // artist yang order-nya dibatalkan seluruhnya akan tetap punya
        // total_sales lama yang basi, karena query agregasi di bawah
        // hanya meng-update baris yang MUNCUL di hasil GROUP BY — baris
        // yang datanya sudah hilang tidak pernah tersentuh.
        ArtistSettlement::where('event_id', $event->id)->get()->each(function (ArtistSettlement $s) {
            $resetPayable = max(0, 0 - (float) $s->deduction);
            $s->update([
                'total_sales' => 0,
                'total_units' => 0,
                'payable_amount' => $resetPayable,
                'status' => $this->deriveStatus((float) $s->paid_amount, $resetPayable),
                'calculated_at' => now(),
            ]);
        });

        $breakdown = $this->salesBreakdownForEvent($event);

        foreach ($breakdown as $artistId => $parts) {
            // 033: total = bagian POS + bagian pre-order, dibangun dari
            // rincian yang SAMA yang dibaca laporan — satu implementasi,
            // jadi kolom POS/pre-order tidak mungkin menyimpang dari total.
            $totalSales = $parts['pos_sales'] + $parts['preorder_sales'];
            $totalUnits = $parts['pos_units'] + $parts['preorder_units'];

            $settlement = ArtistSettlement::firstOrNew([
                'event_id' => $event->id,
                'artist_id' => $artistId,
            ]);

            $settlement->total_sales = $totalSales;
            // Unit preorder diproporsikan (bisa pecahan saat pembayaran
            // baru sebagian) — dibulatkan ke integer di titik tulis ini
            // karena kolomnya integer, konsisten dengan bagaimana uang
            // yang diakui juga bertambah bertahap seiring pembayaran.
            $settlement->total_units = (int) round($totalUnits);
            $settlement->payable_amount = $totalSales - $settlement->deduction;
            $settlement->calculated_at = now();

            if (! $settlement->exists) {
                $settlement->status = 'unpaid';
                $settlement->paid_amount = 0;
            } else {
                $settlement->status = $this->deriveStatus((float) $settlement->paid_amount, (float) $settlement->payable_amount);
            }

            $settlement->save();
        }

        return $breakdown;
    }

    /**
     * 033-seller-recap-pos-preorder-split — rincian penjualan per artist
     * di satu event, dipisah menurut jenisnya:
     *
     *  - POS: order_items dari order 'completed' (nilai penuh);
     *  - pre-order: preorder_items dari pre-order non-'cancelled', hanya
     *    porsi yang SUDAH terbayar (fraction = uang terkumpul di payments,
     *    tanpa verification='rejected', dibagi preorders.subtotal — bukan
     *    cache preorders.paid_amount, yang bisa melenceng; aturan 010).
     *
     * Dipakai recalculateForEvent() untuk menulis total settlement dan oleh
     * ReportController untuk kolom POS/pre-order, supaya keduanya tidak
     * punya dua rumus yang bisa berbeda. Hasil: [artist_id => [pos_sales,
     * pos_units, preorder_sales, preorder_units]] (float; pos_units bulat,
     * preorder_units bisa pecahan). Query tangan-tulis → filter data_mode
     * EKSPLISIT (tidak mewarisi global scope Eloquent).
     *
     * @return \Illuminate\Support\Collection<int, array{pos_sales: float, pos_units: float, preorder_sales: float, preorder_units: float}>
     */
    public function salesBreakdownForEvent(Event $event): \Illuminate\Support\Collection
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat ReportController::sales()
            ->selectRaw('order_items.artist_id, SUM(order_items.line_total) as total_sales, SUM(order_items.qty) as total_units')
            ->groupBy('order_items.artist_id')
            ->get()
            ->keyBy('artist_id');

        // 010-split-payment-preorder-reports (US5, research.md R1) —
        // agregasi PARALEL atas preorder_items; preorder 'cancelled'
        // dikeluarkan sepenuhnya.
        $collected = DB::table('payments')
            ->select('preorder_id', DB::raw('SUM(amount) as collected'))
            ->whereNotNull('preorder_id')
            ->where('verification', '!=', 'rejected')
            ->where('data_mode', ModeGate::current())
            ->groupBy('preorder_id');

        $fraction = 'CASE WHEN preorders.subtotal > 0 THEN COALESCE(pc.collected, 0) / preorders.subtotal ELSE 0 END';

        $preorderRows = DB::table('preorder_items')
            ->join('preorders', 'preorders.id', '=', 'preorder_items.preorder_id')
            ->leftJoinSub($collected, 'pc', 'pc.preorder_id', '=', 'preorders.id')
            ->where('preorders.event_id', $event->id)
            ->where('preorders.status', '!=', 'cancelled')
            ->where('preorder_items.data_mode', ModeGate::current())
            ->selectRaw("
                preorder_items.artist_id,
                SUM(preorder_items.line_total * ({$fraction})) as total_sales,
                SUM(preorder_items.qty * ({$fraction})) as total_units
            ")
            ->groupBy('preorder_items.artist_id')
            ->get()
            ->keyBy('artist_id');

        return $rows->keys()->merge($preorderRows->keys())->unique()->mapWithKeys(function ($artistId) use ($rows, $preorderRows) {
            $order = $rows->get($artistId);
            $preorder = $preorderRows->get($artistId);

            return [$artistId => [
                'pos_sales' => (float) ($order->total_sales ?? 0),
                'pos_units' => (float) ($order->total_units ?? 0),
                'preorder_sales' => (float) ($preorder->total_sales ?? 0),
                'preorder_units' => (float) ($preorder->total_units ?? 0),
            ]];
        });
    }

    public function recordPayment(ArtistSettlement $settlement, float $amount): ArtistSettlement
    {
        $newPaid = (float) $settlement->paid_amount + $amount;

        $settlement->update([
            'paid_amount' => $newPaid,
            'status' => $this->deriveStatus($newPaid, (float) $settlement->payable_amount),
            'paid_at' => now(),
        ]);

        return $settlement->fresh();
    }

    private function deriveStatus(float $paid, float $payable): string
    {
        if ($paid <= 0) return 'unpaid';
        if ($paid < $payable) return 'partial';
        return 'paid';
    }
}
