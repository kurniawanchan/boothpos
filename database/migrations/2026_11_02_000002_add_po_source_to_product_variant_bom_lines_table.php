<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 034-seller-po-bom — baris BOM kini menunjuk BARIS PURCHASE ORDER.
 *
 * Tabel yang sama DIPERLUAS (bukan diganti): baris tanpa
 * purchase_order_item_id adalah baris LEGACY — tetap terlihat, tetap
 * dihitung dengan harga acuan vendor seperti sebelumnya, dan bisa diganti
 * satu per satu. Kolom item_name, line_type, po_number, vendor_id, vendor_name dan unit_cost
 * adalah SNAPSHOT yang disalin saat baris dibuat, supaya harga PO yang
 * berubah, PO yang dibatalkan, atau vendor/bahan yang dihapus tidak pernah
 * mengubah biaya yang sudah tercatat (riwayat biaya harus akurat).
 *
 * URUTAN LANGKAH di bawah penting: UNIQUE(product_variant_id, material_id)
 * sekarang juga berfungsi sebagai index pendukung FK product_variant_id.
 * MySQL menolak menghapusnya selama tidak ada index lain yang bisa dipakai
 * FK itu — jadi index biasa DITAMBAHKAN DULU, baru unique lama dihapus.
 * Unique lama dibuang karena satu varian sah memakai dua baris PO dari
 * bahan yang sama dengan harga berbeda; yang dilarang kini hanya baris PO
 * yang SAMA dua kali (aturan "satu legacy per bahan" dijaga di service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_bom_lines', function (Blueprint $table) {
            $table->index('product_variant_id', 'idx_bom_lines_variant');
        });

        Schema::table('product_variant_bom_lines', function (Blueprint $table) {
            $table->dropUnique(['product_variant_id', 'material_id']);
        });

        Schema::table('product_variant_bom_lines', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->change();

            $table->foreignId('purchase_order_item_id')->nullable()->after('material_id')
                ->constrained('purchase_order_items')->restrictOnDelete();
            $table->enum('line_type', ['material', 'service'])->default('material')->after('purchase_order_item_id');
            $table->string('item_name')->nullable()->after('line_type');
            $table->string('po_number', 30)->nullable()->after('item_name');
            $table->foreignId('vendor_id')->nullable()->after('po_number')
                ->constrained('vendors')->nullOnDelete();
            $table->string('vendor_name')->nullable()->after('vendor_id');
            $table->decimal('unit_cost', 14, 2)->nullable()->after('vendor_name');

            $table->unique(['product_variant_id', 'purchase_order_item_id'], 'uq_bom_lines_variant_po_item');
        });
    }

    /**
     * Catatan keterbatasan: unique lama (variant, material) hanya bisa
     * dipulihkan bila tidak ada pelanggaran; baris PO bahan-sama membuatnya
     * gagal. Kolom/index baru tetap dibuang apa pun hasilnya.
     */
    public function down(): void
    {
        Schema::table('product_variant_bom_lines', function (Blueprint $table) {
            $table->dropUnique('uq_bom_lines_variant_po_item');
            $table->dropConstrainedForeignId('purchase_order_item_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn(['line_type', 'item_name', 'po_number', 'vendor_name', 'unit_cost']);
        });

        Schema::table('product_variant_bom_lines', function (Blueprint $table) {
            $table->unique(['product_variant_id', 'material_id']);
        });

        Schema::table('product_variant_bom_lines', function (Blueprint $table) {
            $table->dropIndex('idx_bom_lines_variant');
        });
    }
};
