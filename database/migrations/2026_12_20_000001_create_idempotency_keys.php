<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / api-gap B15 — kunci idempotensi untuk aksi tab yang BUKAN pembuat dokumen
 * (tambah/ubah baris, kirim ke dapur). Tanpa ini, klien Android yang timeout lalu
 * mengulang `POST /tabs/{id}/items` atau `/send` membuat baris/tiket ganda.
 *
 * Bukan `HasDataMode`: ini data operasional transport, bukan data bisnis; barisnya
 * hanya membuktikan "permintaan ini sudah pernah diproses" dan menyimpan jawabannya.
 * `response_body` NULL = permintaan masih berjalan (bukan "gagal").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            // sha256(method + path + body): kunci yang sama untuk permintaan berbeda = salah pakai.
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            // Dibatasi per pengguna: dua kasir yang kebetulan memakai kunci sama tidak saling memutar ulang.
            $table->unique(['user_id', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
