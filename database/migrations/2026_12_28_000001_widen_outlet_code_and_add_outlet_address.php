<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 079 — kode outlet 3–5 huruf/angka yang bisa disunting, plus alamat outlet.
 *
 * Kode outlet ikut tertanam di kode produk dan SKU (outlet-kategori-segmen, SKU = prefiks-urutan), jadi
 * melebarkan kode ke 5 karakter melebarkan prefiks dari 10 ke 12 dan SKU dari 14 ke 16. Kolom `char` berukuran
 * tetap dilebarkan lewat ALTER TABLE mentah, pola yang sama dengan 2026_10_25_000001 (Blueprint::change() butuh
 * doctrine/dbal yang tidak terpasang). Baris lama tidak disentuh: kode produk/SKU tidak pernah diregenerasi.
 *
 * BUG YANG AKAN TERJADI kalau indeks lama dibiarkan: `artists_code_unique` adalah UNIQUE biasa pada `code`, jadi
 * outlet yang sudah dihapus (soft delete) tetap memegang kodenya di tingkat database, padahal validasi sengaja
 * mengabaikan baris terhapus ("kode outlet yang dihapus boleh dipakai ulang"). Hasilnya: validasi lolos lalu
 * INSERT gagal dengan galat database. Diganti kolom turunan `code_live` (kode hanya bila belum terhapus) yang
 * memegang UNIQUE-nya; NULL tidak saling bentrok, dan collation `utf8mb4_unicode_ci` menjaga "tanpa beda huruf".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outlets DROP INDEX artists_code_unique');
        DB::statement('ALTER TABLE outlets MODIFY code VARCHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE outlets ADD COLUMN address TEXT NULL AFTER name');
        DB::statement(
            'ALTER TABLE outlets ADD COLUMN code_live VARCHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci '
            .'GENERATED ALWAYS AS (IF(deleted_at IS NULL, code, NULL)) VIRTUAL, '
            .'ADD UNIQUE INDEX outlets_code_live_unique (code_live)'
        );

        DB::statement('ALTER TABLE products MODIFY code_prefix CHAR(12) NOT NULL');
        DB::statement('ALTER TABLE product_variants MODIFY sku CHAR(16) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY sku_snapshot CHAR(16) NOT NULL');
        DB::statement('ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(16) NOT NULL');
    }

    /**
     * Gagal (sengaja) bila sudah ada kode outlet/prefiks/SKU yang lebih panjang dari lebar lama: memotongnya
     * diam-diam akan menabrakkan kode. Ganti dulu kode-kode itu, baru turunkan.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY sku_snapshot CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE product_variants MODIFY sku CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE products MODIFY code_prefix CHAR(10) NOT NULL');

        DB::statement('ALTER TABLE outlets DROP INDEX outlets_code_live_unique, DROP COLUMN code_live');
        DB::statement('ALTER TABLE outlets DROP COLUMN address');
        DB::statement('ALTER TABLE outlets MODIFY code CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE outlets ADD UNIQUE INDEX artists_code_unique (code)');
    }
};
