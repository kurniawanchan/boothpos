<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Segmen produk boleh sampai 6 karakter, termasuk satu tanda hubung (spec 046).
 * Kolom lama dibuat untuk segmen 3 huruf: products.product_segment char(3),
 * code_prefix char(10), dan sku char(14) dengan dua sku_snapshot char(14) yang
 * menyalinnya. Kolom-kolom itu dilebarkan ke batas baru (ProductSegment::MAX_*).
 *
 * active_sku (spec 045) adalah kolom turunan dari sku dengan indeks unik; MySQL
 * menolak mengubah kolom dasarnya, jadi kolom turunan dan indeksnya dihapus dulu,
 * lalu dipasang lagi di lebar baru.
 *
 * Migration BARU, bukan menyunting migration lama (prefiks tanggal bersifat
 * load-bearing, lihat CLAUDE.md). Raw ALTER TABLE, sama seperti
 * 2026_10_25_000001 (Blueprint::change() butuh doctrine/dbal yang tidak terpasang).
 *
 * Rollback (down) hanya berhasil selama tidak ada baris yang lebih panjang dari
 * lebar lama: MySQL strict mode menolak pemotongan diam-diam, sehingga rollback
 * berhenti dengan galat, bukan merusak kode/SKU yang sudah tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE product_variants DROP INDEX product_variants_active_sku_unique');
        DB::statement('ALTER TABLE product_variants DROP COLUMN active_sku');

        DB::statement('ALTER TABLE products MODIFY product_segment CHAR(6) NOT NULL');
        DB::statement('ALTER TABLE products MODIFY code_prefix CHAR(13) NOT NULL');
        DB::statement('ALTER TABLE product_variants MODIFY sku CHAR(20) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY sku_snapshot CHAR(20) NOT NULL');
        DB::statement('ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(20) NOT NULL');

        DB::statement(
            'ALTER TABLE product_variants ADD COLUMN active_sku CHAR(20) '
            .'GENERATED ALWAYS AS (IF(is_active = 1 AND deleted_at IS NULL, sku, NULL)) STORED AFTER sku'
        );
        DB::statement('ALTER TABLE product_variants ADD UNIQUE INDEX product_variants_active_sku_unique (active_sku)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants DROP INDEX product_variants_active_sku_unique');
        DB::statement('ALTER TABLE product_variants DROP COLUMN active_sku');

        DB::statement('ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE order_items MODIFY sku_snapshot CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE product_variants MODIFY sku CHAR(14) NOT NULL');
        DB::statement('ALTER TABLE products MODIFY code_prefix CHAR(10) NOT NULL');
        DB::statement('ALTER TABLE products MODIFY product_segment CHAR(3) NOT NULL');

        DB::statement(
            'ALTER TABLE product_variants ADD COLUMN active_sku CHAR(14) '
            .'GENERATED ALWAYS AS (IF(is_active = 1 AND deleted_at IS NULL, sku, NULL)) STORED AFTER sku'
        );
        DB::statement('ALTER TABLE product_variants ADD UNIQUE INDEX product_variants_active_sku_unique (active_sku)');
    }
};
