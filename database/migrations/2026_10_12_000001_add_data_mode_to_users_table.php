<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 003-seed-demo-live follow-up (2026-09-03) — 'users' TIDAK memakai
 * HasDataMode/DataModeScope SENGAJA (beda dengan 20 tabel bisnis
 * lainnya): scope global di model User akan ikut menyaring resolusi user
 * lewat Sanctum saat autentikasi, yang berarti berpindah mode bisa
 * membuat sesi login siapa pun mendadak "tidak ditemukan". Kolom ini
 * HANYA dipakai UserController::index() untuk menyaring TAMPILAN daftar
 * Users, ditulis manual di User::booted() (creating), bukan lewat trait
 * yang mendaftarkan scope. Lihat CLAUDE.md bagian "Seed data and
 * DEMO/LIVE mode".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_users_data_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('data_mode');
        });
    }
};
