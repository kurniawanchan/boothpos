<?php

namespace App\Services;

use App\Models\Device;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * 048 / T028 (api-gap B12) — SATU jalur alokasi nomor struk per terminal (`T01-000124`) dan nomor antrean per
 * outlet per hari bisnis (`A-024`).
 *
 * Konkurensi: baris penghitung dikunci (`lockForUpdate`) di dalam transaksi pemanggil, jadi dua pembayaran serentak di
 * terminal yang sama tidak pernah mendapat nomor sama dan nomor tidak berlubang selama transaksi pemanggil commit.
 * PANGGIL di dalam transaksi yang sama dengan perubahan statusnya: bila transaksi batal, penghitung ikut kembali.
 */
class ReceiptNumbering
{
    public function receiptFor(Device $device): string
    {
        $row = DB::table('terminal_counters')->where('device_id', $device->id)->lockForUpdate()->first();
        if ($row === null) {
            DB::table('terminal_counters')->insert(['device_id' => $device->id, 'receipt_seq' => 0]);
            $row = DB::table('terminal_counters')->where('device_id', $device->id)->lockForUpdate()->first();
        }
        $next = (int) $row->receipt_seq + 1;
        DB::table('terminal_counters')->where('device_id', $device->id)->update(['receipt_seq' => $next]);

        return sprintf('%s-%06d', $device->code, $next);
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
