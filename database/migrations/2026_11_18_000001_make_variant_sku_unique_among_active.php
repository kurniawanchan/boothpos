<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SKU unik hanya di antara varian AKTIF (spec 045). Varian yang dinonaktifkan karena
 * punya riwayat, atau yang dihapus, tidak menahan nomornya: nomor itu boleh dipakai
 * lagi. Indeks unik biasa tidak bisa begitu, jadi SKU disalin ke kolom turunan
 * active_sku (NULL bila tidak aktif / dihapus) dan indeks unik dipasang di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE product_variants DROP INDEX product_variants_sku_unique');
        DB::statement(
            'ALTER TABLE product_variants ADD COLUMN active_sku CHAR(14) '
            .'GENERATED ALWAYS AS (IF(is_active = 1 AND deleted_at IS NULL, sku, NULL)) STORED AFTER sku'
        );
        DB::statement('ALTER TABLE product_variants ADD UNIQUE INDEX product_variants_active_sku_unique (active_sku)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants DROP INDEX product_variants_active_sku_unique');
        DB::statement('ALTER TABLE product_variants DROP COLUMN active_sku');
        DB::statement('ALTER TABLE product_variants ADD UNIQUE INDEX product_variants_sku_unique (sku)');
    }
};
