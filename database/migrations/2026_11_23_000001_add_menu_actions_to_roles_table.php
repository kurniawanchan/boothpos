<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 057 — izin AKSI per menu pada peran. `menu_keys` tetap daftar menu yang boleh dibuka; kolom ini menyimpan,
 * per menu, aksi tambahan yang boleh dilakukan: { "sales": ["void", "verify"], "pos": [] }.
 *
 * Sengaja NULLABLE tanpa backfill: NULL (atau menu tanpa entri) berarti AKSES PENUH — perilaku lama — sehingga
 * semua peran yang sudah ada tak berubah sedikit pun sampai owner/admin mengeditnya (spec FR-006/FR-007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('menu_actions')->nullable()->after('menu_keys');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('menu_actions');
        });
    }
};
