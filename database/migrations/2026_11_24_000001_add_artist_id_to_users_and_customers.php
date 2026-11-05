<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 058 — pengguna terikat ke SATU seller (artist). `users.artist_id` NULL = tanpa batasan (perilaku lama), jadi
 * semua pengguna yang ada tak berubah dan tak ada backfill.
 *
 * `customers.artist_id` = seller asal pelanggan yang dibuat oleh pengguna ber-seller; tanpa itu kasir seller tak
 * bisa memasang pelanggan baru ke penjualan parsial sebelum pelanggan itu punya order (research D3).
 * restrictOnDelete pada users: seller yang masih dipakai pengguna tidak boleh hilang diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable()->after('role_id')->constrained('artists')->restrictOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable()->constrained('artists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artist_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artist_id');
        });
    }
};
