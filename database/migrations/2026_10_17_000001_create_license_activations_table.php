<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 018-license-activation — bukti aktivasi instalasi ini sendiri, terikat
 * ke fingerprint mesin yang menjalankannya (research.md R3/R7). Paling
 * banyak SATU baris pernah ada (instalasi single-tenant). SENGAJA BUKAN
 * HasDataMode — ini data keamanan level instalasi (menggambarkan
 * instalasi itu sendiri, bukan data bisnis/transaksional), kategori yang
 * sama dengan payment_channels/activity_logs di CLAUDE.md. Beralih ke
 * mode DEMO tidak boleh terlihat seperti "mencabut lisensi" aplikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            // Hanya untuk audit/dukungan — tidak pernah dicek ulang
            // validitasnya setelah aktivasi (validasi tanda tangan sudah
            // selesai saat submit); yang benar-benar digerbang setiap
            // request adalah machine_fingerprint_hash di bawah.
            $table->string('license_id', 64);
            $table->string('issued_to', 150);
            // Hash satu-arah (Hash::make), bukan Crypt::encryptString —
            // pemeriksaan hanya butuh KESETARAAN, tidak pernah butuh
            // membalikkan nilai fingerprint aslinya (sama seperti
            // password dan kode aktivasi company di 017).
            $table->string('machine_fingerprint_hash');
            $table->timestamp('activated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_activations');
    }
};
