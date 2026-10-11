<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — fakta item (nama pendek, kategori pajak, estimasi persiapan, item ritel) dan
 * siklus hidup (diterbitkan / didiskontinu). Status TIDAK disimpan: draft = belum pernah diterbitkan
 * (`published_at` null), discontinued = `discontinued_at` terisi, selain itu active. `is_active` tetap
 * menjadi penanda "bisa dipesan" yang dibaca POS, impor, dan laporan — hanya ProductLifecycleService yang
 * mengubahnya (research.md D5).
 *
 * Backfill: semua produk yang ada dianggap sudah terbit (`published_at = created_at`); yang nonaktif
 * dianggap didiskontinu (`discontinued_at = updated_at`). DDL MySQL tidak ikut transaksi: tiap kolom
 * dicek sendiri supaya aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'short_name')) {
                $table->string('short_name', 24)->nullable()->after('name');
            }
            if (! Schema::hasColumn('products', 'tax_category')) {
                $table->string('tax_category', 40)->nullable();
            }
            if (! Schema::hasColumn('products', 'prep_minutes')) {
                $table->unsignedSmallInteger('prep_minutes')->nullable();
            }
            if (! Schema::hasColumn('products', 'is_retail')) {
                $table->boolean('is_retail')->default(false);
            }
            if (! Schema::hasColumn('products', 'published_at')) {
                $table->timestamp('published_at')->nullable();
            }
            if (! Schema::hasColumn('products', 'discontinued_at')) {
                $table->timestamp('discontinued_at')->nullable();
            }
        });

        DB::table('products')->whereNull('published_at')->update(['published_at' => DB::raw('created_at')]);
        DB::table('products')->where('is_active', 0)->whereNull('discontinued_at')->update(['discontinued_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    public function down(): void
    {
        foreach (['short_name', 'tax_category', 'prep_minutes', 'is_retail', 'published_at', 'discontinued_at'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                Schema::table('products', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
