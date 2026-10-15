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
 *
 * 040-recap-pos-transactions-only — settlement HANYA memuat penjualan POS.
 * Fitur 033 sempat menambahkan bagian pre-order yang sudah terbayar ke
 * total_sales/total_units; itu dibalik atas permintaan pemilik produk
 * (rekap = apa yang terjual lewat kasir). Total tersimpan, Payable, dan
 * "Record payment" harus sama dengan kolom Penjualan di layar, jadi
 * agregasi pre-order dihapus dari jalur ini sepenuhnya (bukan sekadar
 * disembunyikan di UI). Laporan Modal Seller / Laba-Rugi punya agregasi
 * pre-order sendiri di ReportController dan tidak terpengaruh.
 *
 * Tindak lanjut 040: rekap di layar/API/ekspor kini hanya Penjual, Unit,
 * Penjualan — endpoint "Record payment" dan field payable/paid/outstanding/
 * status dihapus dari API. Kolom-kolomnya di artist_settlements tetap
 * dipelihara di sini (tanpa migrasi) sebagai snapshot saat event ditutup;
 * paid_amount lama tidak pernah disentuh.
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
        // yang datanya sudah hilang tidak pernah tersentuh. Sejak 040 ini
        // juga yang membersihkan total lama seller yang dulu punya angka
        // dari pre-order; paid_amount tidak pernah disentuh.
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

        foreach ($this->posSalesForEvent($event) as $artistId => $pos) {
            $settlement = ArtistSettlement::firstOrNew([
                'event_id' => $event->id,
                'artist_id' => $artistId,
            ]);

            $settlement->total_sales = $pos['sales'];
            $settlement->total_units = (int) $pos['units'];
            $settlement->payable_amount = $pos['sales'] - $settlement->deduction;
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

    /**
     * Penjualan POS per artist di satu event: order_items dari order
     * 'completed' (nilai penuh; order void tidak ikut). Query tangan-tulis →
     * filter data_mode EKSPLISIT (tidak mewarisi global scope Eloquent).
     *
     * @return \Illuminate\Support\Collection<int, array{sales: float, units: float}>
     */
    public function posSalesForEvent(Event $event): \Illuminate\Support\Collection
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.status', 'completed')
            ->where('order_items.data_mode', ModeGate::current()) // 003-seed-demo-live, lihat ReportController::sales()
            ->selectRaw('order_items.artist_id, SUM(order_items.line_total) as total_sales, SUM(order_items.qty) as total_units')
            ->groupBy('order_items.artist_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->artist_id => [
                'sales' => (float) $row->total_sales,
                'units' => (float) $row->total_units,
            ]]);
    }

    private function deriveStatus(float $paid, float $payable): string
    {
        if ($paid <= 0) return 'unpaid';
        if ($paid < $payable) return 'partial';
        return 'paid';
    }
}
