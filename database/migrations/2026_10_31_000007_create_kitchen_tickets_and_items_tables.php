<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 028 US4 — tiket dapur. Domain dapur SENGAJA terpisah dari order_items
 * (research.md Keputusan 2): baris yang dibatalkan DIHAPUS dari order_items,
 * sehingga tidak ada query laporan yang perlu menyaring status "batal"; tiket
 * menyimpan SNAPSHOT (nama, jumlah, modifier, catatan) agar layar stasiun tetap
 * bisa menampilkan item yang dibatalkan setelah barisnya hilang.
 *
 * Transisi status hanya maju dan masing-masing terjadi paling banyak sekali,
 * jadi kolom *_at/*_by per langkah menggantikan tabel riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // NULL = item tanpa stasiun (langsung siap saji, tidak tampil di layar stasiun mana pun).
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_kitchen_tickets_data_mode');
            $table->timestamps();

            $table->index('order_id');
            $table->index(['station_id', 'sent_at']);
        });

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained('kitchen_tickets')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('name_snapshot', 255);
            $table->unsignedInteger('qty');
            $table->json('modifiers')->nullable();
            $table->string('note', 255)->nullable();
            $table->enum('status', ['new', 'preparing', 'ready', 'served', 'cancelled'])->default('new');
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('preparing_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ready_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_kitchen_ticket_items_data_mode');
            $table->timestamps();

            $table->index('status');
            $table->index('order_item_id');
        });

        DB::statement('ALTER TABLE kitchen_ticket_items ADD CONSTRAINT chk_kitchen_ticket_items_qty CHECK (qty > 0)');
    }

    public function down(): void
    {
        // BUG YANG DITEMUKAN & DIPERBAIKI (verifikasi migrasi pada data nyata): rollback berjalan dari
        // migrasi TERAKHIR ke yang pertama, tetapi penjaga "masih ada tab terbuka" semula hanya ada di
        // 2026_10_31_000006 — jadi saat ia menolak, migrasi 000008 dan 000007 (yang MENGHAPUS tabel tiket
        // dapur) sudah berjalan: tiket tab yang masih berjalan hilang dan skema tinggal setengah.
        // DDL MySQL tidak ikut transaksi, jadi penolakan harus terjadi SEBELUM perubahan apa pun.
        if (\Illuminate\Support\Facades\DB::table('orders')->where('status', 'open')->exists()) {
            throw new \RuntimeException('Rollback ditolak: masih ada tab terbuka (orders.status = open). Selesaikan atau batalkan dulu.');
        }

        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
    }
};
