<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 054 (US8) — referensi transaksi: catatan teks dan/atau SATU gambar pada sebuah penjualan POS
 * (mis. foto dari WhatsApp yang diterima sebelum penjualannya dicatat). 0..1 per penjualan, jadi
 * kolom pada `orders` — bukan tabel baru — dan daftar Sales cukup memeriksa dua kolom ini untuk tahu
 * "ada referensi". Gambar disimpan di disk privat (`local`), tidak pernah publik. Semua kolom
 * nullable: penjualan lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('reference_note')->nullable()->after('notes');
            $table->string('reference_image_path', 255)->nullable()->after('reference_note');
            $table->string('reference_image_mime', 50)->nullable()->after('reference_image_path');
            $table->foreignId('reference_updated_by')->nullable()->after('reference_image_mime')->constrained('users')->nullOnDelete();
            $table->timestamp('reference_updated_at')->nullable()->after('reference_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_updated_by');
            $table->dropColumn(['reference_note', 'reference_image_path', 'reference_image_mime', 'reference_updated_at']);
        });
    }
};
