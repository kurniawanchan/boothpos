<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / T049 (api-gap B2, S3) — persetujuan di tempat (`overrides`) dan metode pembayaran EDC.
 *
 * `overrides` menyimpan HASH token (bukan token), hash subjek aksi (dihitung server), penyetuju, peminta,
 * dan masa berlaku 120 dtk. Token sekali pakai: `consumed_at` diisi di transaksi aksi yang disetujui.
 * `payments.approval_code` menyimpan kode approval EDC saja — nomor kartu TIDAK pernah disimpan.
 * Nilai `edc` ditambahkan ke ENUM `payments.method`; nilai lama tetap. `down()` menolak bila masih ada
 * pembayaran EDC, supaya tidak ada baris yang kehilangan nilainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->string('action', 40);
            $table->char('subject_hash', 64);
            $table->char('token_hash', 64)->unique('uniq_overrides_token_hash');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->constrained('users');
            $table->string('reason', 200)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_by_ref', 120)->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_overrides_data_mode');
            $table->timestamps();

            $table->index(['subject_hash', 'consumed_at'], 'idx_overrides_subject');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('approval_code', 12)->nullable();
        });

        DB::statement("ALTER TABLE payments MODIFY method ENUM('cash', 'bank_transfer', 'qr_ewallet', 'edc') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('payments')->where('method', 'edc')->exists()) {
            throw new RuntimeException('Tidak bisa diturunkan: masih ada pembayaran EDC. Hapus atau ubah metodenya dulu.');
        }

        DB::statement("ALTER TABLE payments MODIFY method ENUM('cash', 'bank_transfer', 'qr_ewallet') NOT NULL");

        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('approval_code'));
        Schema::dropIfExists('overrides');
    }
};
