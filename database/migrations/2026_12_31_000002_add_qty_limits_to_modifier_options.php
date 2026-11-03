<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 089 — jumlah per pilihan modifier (mis. Telur ×2). `min_qty`/`max_qty` berlaku SAAT pilihan dipilih (jumlah 0 =
 * tidak dipilih). Bawaan 1/1 membuat semua pilihan lama tetap "ada atau tidak ada". `max_qty` NULL = tanpa batas atas
 * (validasi API membatasi 50). CHECK memakai pola `DB::statement` karena Blueprint tidak punya CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_options', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_qty')->default(1)->after('price_delta');
            $table->unsignedTinyInteger('max_qty')->nullable()->default(1)->after('min_qty');
        });

        DB::statement('ALTER TABLE modifier_options ADD CONSTRAINT chk_modifier_options_qty_range CHECK (min_qty >= 1 AND (max_qty IS NULL OR max_qty >= min_qty))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE modifier_options DROP CHECK chk_modifier_options_qty_range');
        Schema::table('modifier_options', function (Blueprint $table) {
            $table->dropColumn(['min_qty', 'max_qty']);
        });
    }
};
