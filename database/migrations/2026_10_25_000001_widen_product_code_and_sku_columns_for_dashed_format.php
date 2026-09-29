<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kode produk dan SKU berubah format dari "VLCSKSEA"/"VLCSKSEA0001" (tanpa
 * pemisah) menjadi "VLC-SK-SEA"/"VLC-SK-SEA-001" (dipisah tanda hubung,
 * urutan varian 3 digit — bukan 4) atas permintaan pengguna. Kolom
 * `char` berukuran TETAP di migration lama (products.code_prefix char(8),
 * product_variants.sku char(12), dan dua kolom sku_snapshot char(12) yang
 * menyalinnya di order_items/preorder_items) tidak lagi cukup untuk
 * format baru (10 dan 14 karakter) — perlu dilebarkan.
 *
 * Migration BARU, bukan menyunting migration lama (CLAUDE.md: prefiks
 * tanggal migration bersifat load-bearing untuk urutan FK, jangan pernah
 * diubah/direname) — kolom yang sudah ada dilebarkan lewat ALTER TABLE
 * mentah (raw DB::statement), sama seperti pola CHECK constraint yang
 * sudah ada di migration orders_and_payments/preorders (Laravel's
 * Blueprint::change() butuh doctrine/dbal yang tidak terpasang di
 * proyek ini).
 *
 * Baris yang sudah ada (format lama, tanpa tanda hubung) TIDAK dimigrasi
 * ulang di sini — kode/SKU bersifat permanen sekali dibuat (F19.4), jadi
 * data lama tetap dalam format lama apa adanya; hanya kode/SKU yang
 * dibuat SETELAH migration ini yang memakai format baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products MODIFY code_prefix CHAR(10) NOT NULL');
        DB::statement('ALTER TABLE product_variants MODIFY sku CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY sku_snapshot CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(14) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(12) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY sku_snapshot CHAR(12) NOT NULL');
        DB::statement('ALTER TABLE product_variants MODIFY sku CHAR(12) NOT NULL');
        DB::statement('ALTER TABLE products MODIFY code_prefix CHAR(8) NOT NULL');
    }
};
