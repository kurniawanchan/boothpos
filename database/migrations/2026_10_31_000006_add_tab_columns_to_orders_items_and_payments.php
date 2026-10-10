<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 028-restaurant-dine-in-ordering — tab terbuka sebagai status baru pada
 * `orders` (research.md Keputusan 1), bukan model terpisah.
 *
 * - Default status TETAP 'completed' — setiap pemanggil lama yang tidak
 *   menyebut status (Order::create di seeder/test) tak berubah perilaku.
 * - `open_table_key` adalah kolom generated + UNIQUE: satu-satunya jaminan
 *   tingkat-database bahwa sebuah meja hanya punya SATU tab terbuka, bahkan
 *   saat dua pelayan membuka meja yang sama di detik yang sama (FR-004).
 *   NULL (selesai/batal/takeaway) tidak pernah bentrok di indeks UNIQUE.
 *   MySQL mensyaratkan FK pada kolom dasar kolom generated tidak memakai
 *   CASCADE/SET NULL — table_id memakai RESTRICT, jadi aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY status ENUM('open', 'completed', 'voided') NOT NULL DEFAULT 'completed'");

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('service_type', ['retail', 'dine_in', 'takeaway'])->default('retail');
            $table->foreignId('table_id')->nullable()->constrained('dining_tables')->restrictOnDelete();
            $table->string('tab_label', 100)->nullable();
            $table->foreignId('split_from_order_id')->nullable()->constrained('orders')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE orders
                ADD COLUMN open_table_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (IF(status = 'open', table_id, NULL)) STORED,
                ADD UNIQUE INDEX uniq_orders_open_table_key (open_table_key)"
        );

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('modifiers')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
        });

        // BUG YANG DITEMUKAN & DIPERBAIKI: versi awal juga menambah
        // `payments.client_ref` di sini. Kolom itu SUDAH dibuat (char(36),
        // UNIQUE) oleh 2026_10_31_000001_add_ledger_columns_to_payments_table
        // dari develop, yang terurut lebih dulu — jadi langkah ini gagal dengan
        // "Duplicate column name 'client_ref'" di database baru. Karena DDL MySQL
        // tidak ikut transaksi, kegagalan itu meninggalkan kolom orders/order_items
        // yang sudah terpasang tapi migrasi tak tercatat, dan percobaan ulang
        // berikutnya patah lebih awal di 'service_type'. Kunci idempotensi tab
        // memakai kolom milik ledger itu (UNIQUE sudah cukup).
    }

    public function down(): void
    {
        // Dilempar SEBELUM perubahan skema apa pun: DDL MySQL tidak ikut
        // transaksi, dan menyempitkan enum saat masih ada baris 'open'
        // akan membuang tab yang sedang berjalan secara diam-diam.
        if (DB::table('orders')->where('status', 'open')->exists()) {
            throw new \RuntimeException('Rollback ditolak: masih ada tab terbuka (orders.status = open). Selesaikan atau batalkan dulu.');
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['modifiers', 'note', 'sent_at']);
        });

        DB::statement('ALTER TABLE orders DROP INDEX uniq_orders_open_table_key, DROP COLUMN open_table_key');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropForeign(['split_from_order_id']);
            $table->dropColumn(['service_type', 'table_id', 'tab_label', 'split_from_order_id']);
        });

        DB::statement("ALTER TABLE orders MODIFY status ENUM('completed', 'voided') NOT NULL DEFAULT 'completed'");
    }
};
