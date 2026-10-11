<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 028-restaurant-dine-in-ordering — kunci idempotensi per PENGIRIMAN pembayaran tab.
 *
 * BUG YANG DITEMUKAN & DIPERBAIKI: `payments.client_ref` (migrasi ledger
 * 2026_10_31_000001) adalah kunci idempotensi per BARIS pembayaran dan UNIQUE.
 * Pembayaran/pisah tagihan tab mengirim SATU `client_ref` untuk seluruh
 * pengiriman, lalu menuliskannya ke setiap baris pembayaran di dalamnya —
 * bayar campur (mis. tunai + QRIS) atau split dengan 2 baris atau lebih
 * menabrak indeks UNIQUE itu (SQLSTATE 23000 / 1062, respons 500).
 *
 * Maka kunci per-pengiriman disimpan di kolom sendiri yang TIDAK unik. Nama
 * field di API tetap `client_ref`; hanya penyimpanannya yang berbeda. Kolom
 * `client_ref` milik ledger tidak disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DDL MySQL tidak ikut transaksi: aman dijalankan ulang bila sebagian sudah terpasang.
        if (Schema::hasColumn('payments', 'submission_ref')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->char('submission_ref', 36)->nullable()->after('client_ref');
            $table->index('submission_ref', 'idx_payments_submission_ref');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'submission_ref')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_submission_ref');
            $table->dropColumn('submission_ref');
        });
    }
};
