<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F13.4 (PRD 7.13, prioritas M) — log aktivitas untuk tindakan sensitif:
 * hapus data, penyesuaian stok, ubah harga. DDL mengikuti
 * docs/schema-pos-mvp.sql BAGIAN 1 nyaris persis.
 *
 * Ditaruh di AKHIR urutan migration (setelah preorders_tables), bukan di
 * dekat 'settings'/'payment_channels' tempat schema-pos-mvp.sql
 * mendefinisikannya — satu-satunya dependensi tabel ini adalah 'users'
 * (sudah ada sejak 2026_10_01_000000), dan menambahkannya di akhir
 * menghindari menyisipkan file baru DI TENGAH urutan tanggal yang sudah
 * ada serta sudah teruji, sesuai catatan README soal tidak mengubah
 * urutan file migration yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
