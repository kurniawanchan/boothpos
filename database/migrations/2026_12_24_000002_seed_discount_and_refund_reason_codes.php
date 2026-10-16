<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 048 / T058, T060 — kategori alasan untuk diskon dan refund, dibaca aplikasi lewat `GET /reason-codes?category=`
 * seperti `sold_out`. Idempoten: baris yang sudah ada dilewati. `down()` hanya menghapus baris dari migrasi ini.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'discount' => [
            ['member', 'Member / pelanggan tetap', 'Member / regular customer'],
            ['promo', 'Promo', 'Promotion'],
            ['damaged', 'Barang rusak', 'Damaged item'],
            ['staff', 'Karyawan', 'Staff'],
            ['other', 'Lainnya', 'Other'],
        ],
        'refund' => [
            ['wrong_item', 'Salah pesan', 'Wrong order'],
            ['customer_request', 'Permintaan pelanggan', 'Customer request'],
            ['quality_issue', 'Masalah kualitas', 'Quality issue'],
            ['other', 'Lainnya', 'Other'],
        ],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $category => $rows) {
            foreach ($rows as $i => [$code, $id, $en]) {
                if (DB::table('reason_codes')->where(['category' => $category, 'code' => $code])->exists()) {
                    continue;
                }
                DB::table('reason_codes')->insert([
                    'category' => $category, 'code' => $code, 'label_id' => $id, 'label_en' => $en,
                    'is_active' => true, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::DEFAULTS as $category => $rows) {
            DB::table('reason_codes')->where('category', $category)
                ->whereIn('code', array_column($rows, 0))->delete();
        }
    }
};
