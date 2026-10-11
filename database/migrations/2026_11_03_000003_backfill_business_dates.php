<?php

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 042-dashboard-revamp — mengisi `completed_at` dan `business_date` untuk baris yang sudah ada.
 *
 * Aturan (research.md D3, keputusan pemilik O2 = zona waktu toko, default Asia/Jakarta):
 *  - orders.completed_at: `created_at` untuk retail; `updated_at` untuk order dine-in/takeaway yang
 *    sudah completed (tab dilunasi saat itu). Order open/voided: kosong.
 *  - orders.business_date: dari `completed_at ?? created_at` dalam zona waktu toko.
 *  - payments.business_date: dari `paid_at ?? created_at`; cashier_sessions: dari `opened_at`.
 *
 * Idempoten dan bisa diulang: hanya menyentuh baris yang masih kosong, per potongan 500 baris.
 * Jalankan `php artisan app:business-date-dry-run` dulu untuk melihat baris yang berpindah hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->whereNull('business_date')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $completedAt = $row->completed_at
                    ?? ($row->status === 'completed'
                        ? ($row->service_type === 'retail' ? $row->created_at : ($row->updated_at ?? $row->created_at))
                        : null);

                $basis = $completedAt ?? $row->created_at;
                if ($basis === null) {
                    continue;
                }

                DB::table('orders')->where('id', $row->id)->update([
                    'completed_at' => $completedAt,
                    'business_date' => BusinessDay::dateFor(CarbonImmutable::parse($basis, 'UTC')),
                ]);
            }
        });

        DB::table('payments')->whereNull('business_date')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $basis = $row->paid_at ?? $row->created_at;
                if ($basis === null) {
                    continue;
                }
                DB::table('payments')->where('id', $row->id)->update([
                    'business_date' => BusinessDay::dateFor(CarbonImmutable::parse($basis, 'UTC')),
                ]);
            }
        });

        DB::table('cashier_sessions')->whereNull('business_date')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $basis = $row->opened_at ?? $row->created_at;
                if ($basis === null) {
                    continue;
                }
                DB::table('cashier_sessions')->where('id', $row->id)->update([
                    'business_date' => BusinessDay::dateFor(CarbonImmutable::parse($basis, 'UTC')),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong: kolom dihapus oleh migrasi 000001; mengosongkan nilai tidak berguna sendirian.
    }
};
