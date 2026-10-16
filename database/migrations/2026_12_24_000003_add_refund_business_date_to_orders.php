<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / S4 (keputusan pemilik) — tanggal bisnis TEMPAT refund diakui. Penjualan asal tetap di tanggal
 * penjualannya; pengurangan refund dihitung pada tanggal ini (konvensi retail "retur hari ini"), sehingga laporan
 * hari-hari sebelumnya dan Z-report shift yang sudah ditutup tidak berubah. Diisi server saat refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('refund_business_date')->nullable()->after('refund_reason_code');
            $table->index(['refund_business_date'], 'idx_orders_refund_business_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_refund_business_date');
            $table->dropColumn('refund_business_date');
        });
    }
};
