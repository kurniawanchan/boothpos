<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 006-purchase-order-and-ops (US4) — snapshot JSON longgar (BUKAN baris
 * item ternormalisasi dengan FK), sengaja: draft harus tetap bisa
 * dilanjutkan bahkan kalau varian/pelanggan yang direferensikan sudah
 * dihapus, cukup menandai baris itu (bukan gagal total atau ikut
 * terhapus lewat cascade) — lihat research.md R8 dan spec edge case.
 * customer_id karena itu KOLOM POLOS, bukan foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->json('cart_snapshot');
            $table->string('label')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_pos_drafts_data_mode');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_drafts');
    }
};
