<?php

namespace App\Services;

use App\Models\ArtistSettlement;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung ULANG (bukan membaca angka lama) hasil penjualan per artist
 * dari order_items setiap dipanggil. artist_settlements berfungsi sebagai
 * cache + tempat mencatat status pembayaran ke artist, BUKAN sumber
 * kebenaran nilai penjualan — sumber kebenarannya selalu order_items.
 */
class SettlementService
{
    public function recalculateForEvent(Event $event): void
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

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.status', 'completed')
            ->selectRaw('order_items.artist_id, SUM(order_items.line_total) as total_sales, SUM(order_items.qty) as total_units')
            ->groupBy('order_items.artist_id')
            ->get();

        foreach ($rows as $row) {
            $settlement = ArtistSettlement::firstOrNew([
                'event_id' => $event->id,
                'artist_id' => $row->artist_id,
            ]);

            $settlement->total_sales = $row->total_sales;
            $settlement->total_units = $row->total_units;
            $settlement->payable_amount = $row->total_sales - $settlement->deduction;
            $settlement->calculated_at = now();

            if (! $settlement->exists) {
                $settlement->status = 'unpaid';
                $settlement->paid_amount = 0;
            } else {
                $settlement->status = $this->deriveStatus((float) $settlement->paid_amount, (float) $settlement->payable_amount);
            }

            $settlement->save();
        }
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
