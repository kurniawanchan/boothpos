<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langkah 2 dari 2 (research.md Keputusan 5). Hanya dijalankan setelah
 * backfill role_id di 2026_10_09_000002 terbukti benar (quickstart.md
 * langkah 1: keempat akun seed melihat layar yang identik dengan sebelum
 * fitur ini). role_id menjadi wajib (non-nullable) dan kolom enum 'role'
 * lama dihapus — User::canAccessMenu() menjadi satu-satunya jalur
 * otorisasi sejak migrasi ini berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->change();
            $table->enum('role', ['owner', 'admin', 'cashier', 'inventory'])->default('cashier')->after('password');
        });
    }
};
