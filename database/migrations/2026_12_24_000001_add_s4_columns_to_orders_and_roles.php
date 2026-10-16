<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / T058–T060 (api-gap B9, B10, B11) — kolom S4:
 *
 *  - `roles.discount_limit_pct`: batas diskon (persen) yang boleh diberikan peran ini TANPA persetujuan.
 *    Disimpan sebagai kolom, bukan di JSON `permissions`: daftar izin itu berisi KUNCI (string) dan dibaca
 *    `Role::allows()` dengan in_array; mencampur angka ke dalamnya memaksa setiap pembaca memfilternya.
 *    NULL = bawaan (pemilik/admin tanpa batas; peran lain 0 — semua diskon butuh persetujuan).
 *  - `orders.receipt_copies`: jumlah cetak ulang struk (tidak termasuk salinan asli). Salinan pertama gratis.
 *  - `orders.refunded_at` / `refunded_by` / `refund_reason_code`: jejak refund. Order TIDAK diubah totalnya;
 *    refund hanya menambah pergerakan stok dan kas keluar di shift berjalan, supaya laporan lama tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->decimal('discount_limit_pct', 5, 2)->nullable()->after('permissions');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_copies')->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_reason_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['receipt_copies', 'refunded_at', 'refund_reason_code']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('discount_limit_pct');
        });
    }
};
