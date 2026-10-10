<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 028 US4 — stasiun persiapan (Dapur, Bar). Nama unik per mode DEMO/LIVE.
 * Stasiun yang pernah dipakai tidak dihapus (penjagaan di StationController):
 * nonaktifkan saja, tiket lama tetap menunjuknya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->smallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_stations_data_mode');
            $table->timestamps();

            $table->unique(['data_mode', 'name'], 'uniq_stations_mode_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
