<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 028-partial-split-payment — payments menjadi buku besar (ledger) yang bisa
 * diaudit, untuk pre-order DAN penjualan POS.
 *
 * - `reference`   : nomor referensi / transaksi dari pengguna (opsional).
 * - `client_ref`  : kunci idempotensi buatan klien (UUID). UNIQUE — dipakai
 *                   supaya klik ganda / coba-ulang setelah koneksi lambat tak
 *                   pernah mencatat pembayaran yang sama dua kali.
 * - `session_id`  : shift kasir tempat uang ITU DITERIMA. Pembayaran susulan
 *                   atas penjualan lama masuk ke shift saat uang diterima,
 *                   bukan shift penjualan aslinya — kalau tidak, kas shift
 *                   yang sudah ditutup (expected_cash tersimpan) tak bisa
 *                   direkonsiliasi lagi. NULL untuk pembayaran pre-order.
 * - `recorded_by` : siapa yang mencatat. NULL untuk baris lama.
 *
 * Backfill: pembayaran milik order di-set ke shift order-nya (itulah shift
 * tempat uangnya diterima saat checkout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('reference', 100)->nullable()->after('notes');
            $table->char('client_ref', 36)->nullable()->unique()->after('reference');
            $table->foreignId('session_id')->nullable()->after('client_ref')
                ->constrained('cashier_sessions')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->after('session_id')
                ->constrained('users')->nullOnDelete();
        });

        DB::statement('UPDATE payments p JOIN orders o ON o.id = p.order_id SET p.session_id = o.session_id WHERE p.order_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropConstrainedForeignId('session_id');
            $table->dropUnique(['client_ref']);
            $table->dropColumn(['reference', 'client_ref']);
        });
    }
};
