<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / T041 (api-gap B3, B4, B5) — kas masuk/keluar, rincian kas awal per pecahan, dan kolom hitungan
 * buta pada shift.
 *
 * `cash_movements.override_id` disiapkan untuk persetujuan (S3/US4) tanpa FK dulu: tabel `overrides`
 * belum ada; FK ditambahkan bersama tabel itu. `device_id` mencatat terminal pembuka shift (null untuk
 * shift dari SPA web). Angka lama (`opening_cash`, `closing_cash`, `expected_cash`, `cash_difference`)
 * tetap ada dan tetap dipakai laporan; kolom baru hanya menambah rincian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cashier_sessions');
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->enum('direction', ['in', 'out']);
            $table->string('reason_code', 40);
            $table->string('reason_note', 200)->nullable();
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('override_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_cash_movements_data_mode');

            $table->index(['session_id', 'created_at'], 'idx_cash_movements_session');
        });

        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT chk_cash_movements_amount CHECK (amount > 0)');

        Schema::create('session_opening_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cashier_sessions')->cascadeOnDelete();
            $table->unsignedInteger('denomination');
            $table->unsignedInteger('qty');
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_session_opening_denom_mode');

            $table->unique(['session_id', 'denomination'], 'uniq_session_opening_denom');
        });

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->json('counted_by_denomination')->nullable();
            $table->decimal('counted_total', 15, 2)->nullable();
            $table->decimal('edc_batch_total', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->unsignedBigInteger('variance_override_id')->nullable();
            $table->json('z_report')->nullable();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn(['counted_by_denomination', 'counted_total', 'edc_batch_total', 'variance', 'variance_override_id', 'z_report']);
        });

        Schema::dropIfExists('session_opening_denominations');
        Schema::dropIfExists('cash_movements');
    }
};
