<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambah pilihan 'both' ke enum events.available_on (di samping
 * 'day_1'/'day_2' dari 2026_10_24_000001) — event bisa ditandai tersedia
 * di KEDUA hari sekaligus, bukan cuma satu. Tidak ada dukungan
 * Schema::table()->enum()->change() tanpa doctrine/dbal (lihat catatan
 * migrasi widen kolom code_prefix/sku), jadi ini raw ALTER TABLE seperti
 * migrasi-migrasi widen kolom lain di proyek ini.
 *
 * Event::availableOnDate() TETAP mengembalikan satu tanggal (Carbon) untuk
 * 'day_1'/'day_2' dan null untuk 'both' — 'both' tidak punya satu tanggal
 * tunggal yang mewakilinya. Konsumen yang butuh rentang tanggal untuk
 * kasus 'both' memakai Event::availableOnRange() (baru), bukan menghitung
 * ulang start_date/end_date sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE events MODIFY available_on ENUM('day_1', 'day_2', 'both') NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE events SET available_on = NULL WHERE available_on = 'both'");
        DB::statement("ALTER TABLE events MODIFY available_on ENUM('day_1', 'day_2') NULL");
    }
};
