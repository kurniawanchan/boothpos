<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 028 US4 — penugasan stasiun. Urutan prioritas saat kirim: produk → kategori →
 * tanpa stasiun (item langsung siap saji). Warisan kategori induk sengaja tidak
 * diimplementasikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['categories', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign([ 'station_id' ]);
                $table->dropColumn('station_id');
            });
        }
    }
};
