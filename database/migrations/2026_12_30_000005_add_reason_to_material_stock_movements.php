<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-31 (permintaan pemilik: koreksi stok bahan tampil di log Inventori → Stok) — alasan koreksi dulu hanya
 * tersimpan di deskripsi log aktivitas, jadi buku besar bahan tidak bisa menampilkannya. Nullable: baris lama dan
 * pergerakan otomatis (penjualan, pembelian, transfer) tidak punya alasan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_stock_movements', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->after('reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_stock_movements', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
