<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer commerce (2026-11-11) — status terbit per varian.
 *
 * Varian tampil di etalase hanya bila `published_at`-nya SENDIRI dan milik
 * produknya sama-sama terisi (aturannya ada di scope model, bukan di sini).
 * NULL = belum terbit; tidak ada backfill, jadi semua varian lama tetap
 * tersembunyi sampai staf menerbitkannya.
 *
 * Kolom baru aman di samping kolom turunan `active_sku` (GENERATED): yang
 * butuh drop/re-add hanyalah perubahan pada kolom `sku` itu sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_active');
            $table->index('published_at', 'idx_product_variants_published_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex('idx_product_variants_published_at');
            $table->dropColumn('published_at');
        });
    }
};
