<?php

namespace App\Services;

use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * 048 / T028 (api-gap B12) — SATU jalur alokasi nomor struk dan nomor antrean per outlet per hari bisnis (`A-024`).
 *
 * 064 — nomor struk kini nomor urut polos PER OUTLET (`000124`), tanpa bagian terminal (terminal dikenali lewat nama dan
 * tertaut ke order lewat `orders.device_id`). Dua outlet boleh sama-sama punya `000001`; keunikan dijaga indeks
 * `(outlet_id, receipt_no)`. Nomor lama (`T01-000124`) tidak berubah dan tak bisa bentrok dengan 6 digit.
 *
 * Konkurensi: baris penghitung dikunci (`lockForUpdate`) di dalam transaksi pemanggil, jadi dua pembayaran serentak di
 * outlet yang sama tidak pernah mendapat nomor sama dan nomor tidak berlubang selama transaksi pemanggil commit.
 * PANGGIL di dalam transaksi yang sama dengan perubahan statusnya: bila transaksi batal, penghitung ikut kembali.
 */
class ReceiptNumbering
{
    public function receiptFor(int $outletId): string
    {
        // insertOrIgnore lalu kunci: dua transaksi pertama serentak tidak saling menabrak saat baris belum ada.
        DB::table('outlet_receipt_counters')->insertOrIgnore(['outlet_id' => $outletId, 'receipt_seq' => 0]);
        $row = DB::table('outlet_receipt_counters')->where('outlet_id', $outletId)->lockForUpdate()->first();
        $next = (int) $row->receipt_seq + 1;
        DB::table('outlet_receipt_counters')->where('outlet_id', $outletId)->update(['receipt_seq' => $next]);

        return sprintf('%06d', $next);
    }

    public function queueFor(int $outletId, ?string $businessDate = null): string
    {
        $date = $businessDate ?? BusinessDay::today();
        DB::table('outlet_queue_counters')->insertOrIgnore(['outlet_id' => $outletId, 'business_date' => $date, 'queue_seq' => 0]);
        $row = DB::table('outlet_queue_counters')->where('outlet_id', $outletId)->where('business_date', $date)->lockForUpdate()->first();
        $next = (int) $row->queue_seq + 1;
        DB::table('outlet_queue_counters')->where('outlet_id', $outletId)->where('business_date', $date)->update(['queue_seq' => $next]);

        return sprintf('A-%03d', $next);
    }
}
