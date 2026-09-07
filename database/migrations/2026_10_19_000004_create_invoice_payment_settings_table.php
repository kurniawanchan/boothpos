<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel singleton (satu baris, id selalu 1) untuk informasi pembayaran
     * invoice (nama bank, nomor rekening, atas nama, instruksi pembayaran)
     * yang dijadikan default `payment_information` saat invoice baru
     * dibuat (FR-008/FR-015, lihat research.md R8').
     *
     * Sengaja BUKAN baris tambahan di tabel `settings` generik: data ini
     * adalah satu record terstruktur (bukan skalar tunggal), jadi tabel
     * khusus lebih pas — sama seperti `payment_channels` yang juga tidak
     * dilebur ke `settings`.
     *
     * Sengaja TIDAK memakai trait HasDataMode: ini data administratif
     * konfigurasi toko (bukan data bisnis/transaksional), satu kategori
     * dengan `payment_channels` — terlihat identik di mode DEMO maupun
     * LIVE. Juga TIDAK ada soft delete karena baris ini tidak pernah
     * "dihapus", hanya diperbarui (updateOrCreate(['id' => 1], ...)).
     */
    public function up(): void
    {
        Schema::create('invoice_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_settings');
    }
};
