<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 063 — riwayat pembukaan-kembali shift kasir yang sudah ditutup (hanya Owner).
 *
 * Append-only: satu baris per pembukaan-kembali, berisi siapa/kapan/alasan dan angka penutupan
 * SEBELUM dikosongkan (shift itu sendiri kembali ke bentuk "terbuka" biasa). Tanpa `data_mode`:
 * tabel ini hanya dijangkau lewat shift yang sudah terfilter mode, dan ikut shift-nya saat clone
 * LIVE → DEMO (`CloneRegistry`, via cashier_sessions.session_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_session_reopens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cashier_sessions')->restrictOnDelete();
            $table->foreignId('reopened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at');
            $table->text('reason');
            $table->timestamp('previous_closed_at')->nullable();
            $table->decimal('previous_closing_cash', 14, 2)->nullable();
            $table->decimal('previous_expected_cash', 14, 2)->nullable();
            $table->decimal('previous_cash_difference', 14, 2)->nullable();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_session_reopens');
    }
};
