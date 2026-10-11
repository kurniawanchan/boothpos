<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — daftar alasan yang bisa diatur admin (research.md D13). Irisan pertama dari
 * katalog kode alasan desain 041, memakai nama tabel yang sama supaya tumbuh ke sana. Kode yang sudah
 * dipakai tidak pernah dihapus, hanya dinonaktifkan. Tidak memakai HasDataMode (data administratif, sama
 * seperti payment_channels).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'sold_out' => [
            ['out_of_stock', 'Bahan habis', 'Out of stock'],
            ['equipment_broken', 'Peralatan rusak', 'Equipment broken'],
            ['quality_issue', 'Masalah kualitas', 'Quality issue'],
            ['other', 'Lainnya', 'Other'],
        ],
        'discontinue' => [
            ['removed_from_menu', 'Dikeluarkan dari menu', 'Removed from menu'],
            ['replaced', 'Diganti item lain', 'Replaced by another item'],
            ['seasonal_end', 'Musim berakhir', 'Seasonal end'],
            ['other', 'Lainnya', 'Other'],
        ],
        'approval_reject' => [
            ['price_too_high', 'Harga terlalu tinggi', 'Price too high'],
            ['price_too_low', 'Harga terlalu rendah', 'Price too low'],
            ['other', 'Lainnya', 'Other'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('reason_codes')) {
            Schema::create('reason_codes', function (Blueprint $table) {
                $table->id();
                $table->string('category', 30);
                $table->string('code', 40);
                $table->string('label_id', 120);
                $table->string('label_en', 120);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
                $table->unique(['category', 'code'], 'uniq_reason_codes_category_code');
            });
        }

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
        Schema::dropIfExists('reason_codes');
    }
};
