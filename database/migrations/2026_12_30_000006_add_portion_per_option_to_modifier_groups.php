<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-31 (permintaan pemilik, lanjutan 087) — grup "tiap pilihan = 1 porsi": memilih SOTO + AYAM BAWANG di grup
 * Rasa berarti dua bungkus mi, jadi harga dasar dihitung dua kali. Bawaan false: grup lama tidak berubah harga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->boolean('portion_per_option')->default(false)->after('max_select');
        });
    }

    public function down(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropColumn('portion_per_option');
        });
    }
};
