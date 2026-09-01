<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 5 — kategori sebelumnya sama sekali tidak punya kolom gambar,
 * padahal produk sudah punya `image_path` sejak awal (lihat
 * create_products_table). Ditambahkan sebagai kolom nullable biasa, bukan
 * lewat backfill data: gambar kategori memang opsional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
