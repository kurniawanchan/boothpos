<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 006-purchase-order-and-ops (US5) — ADITIF terhadap cashier_sessions.
 * opening_cash yang sudah ada: kolom itu TETAP ada dan tetap wajib diisi
 * (server merekonsiliasi jumlahnya terhadap total baris di tabel ini,
 * lihat CashierSessionController::store()), bukan digantikan. Sesi lama
 * tanpa rincian per-artist tetap berfungsi apa adanya — lihat research.md
 * R9 dan spec.md Acceptance Scenario 4 (US5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_opening_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cashier_sessions')->cascadeOnDelete();
            // artist_id NULL = jumlah tidak diatribusikan ke artist manapun.
            $table->foreignId('artist_id')->nullable()->constrained('artists')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_session_opening_cash_entries_data_mode');
            $table->timestamp('created_at')->nullable();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_opening_cash_entries');
    }
};
