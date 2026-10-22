<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 065 — shift aplikasi dan shift web dipisah (research D4). "Sumber" shift TIDAK disimpan terpisah: shift aplikasi
 * = `device_id` terisi, shift web = `device_id` NULL. Aturan "satu shift terbuka per pengguna per sumber" mencari
 * lewat (user_id, status, device_id), jadi cukup satu indeks; tanpa kolom baru, tanpa backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'device_id'], 'idx_cashier_sessions_user_status_device');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_cashier_sessions_user_status_device');
        });
    }
};
