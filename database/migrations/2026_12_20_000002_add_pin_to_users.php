<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / api-gap B1 — login PIN 6 digit untuk terminal kasir.
 *
 * PIN adalah kredensial kedua yang lebih lemah dari kata sandi (6 digit = 1 juta kemungkinan), sehingga
 * kekuatannya datang dari kunci server (5 salah → terkunci 15 menit), bukan dari panjangnya. Hanya hash
 * yang disimpan; `pin_hash` NULL = belum punya PIN (tidak bisa masuk lewat PIN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
            $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->after('pin_hash');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_attempts');
            $table->timestamp('pin_changed_at')->nullable()->after('pin_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_failed_attempts', 'pin_locked_until', 'pin_changed_at']);
        });
    }
};
