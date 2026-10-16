<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 048 / T025 — kolom order untuk terminal POS: pajak (snapshot), nomor struk & antrean, jumlah tamu, pra-bill, perangkat.
 *
 * Semua NULLable/default 0 supaya order lama dan instalasi tanpa pajak tidak berubah. `tax_amount`/`tax_rate_snapshot`
 * adalah SNAPSHOT saat transaksi (Konstitusi IV): tarif yang diubah kemudian tidak menulis ulang order lama.
 * `receipt_no` unik lintas mode data (belajar dari order_number: nilai unik harus dicek di kedua mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax_amount', 15, 2)->default(0)->after('discount_amount');
            $table->decimal('tax_rate_snapshot', 5, 2)->default(0)->after('tax_amount');
            $table->string('receipt_no', 20)->nullable()->unique()->after('order_number');
            $table->string('queue_no', 8)->nullable()->after('receipt_no');
            $table->unsignedTinyInteger('guest_count')->nullable()->after('queue_no');
            $table->timestamp('prebill_at')->nullable()->after('guest_count');
            $table->unsignedTinyInteger('prebill_count')->default(0)->after('prebill_at');
            $table->foreignId('device_id')->nullable()->after('prebill_count')->constrained('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropUnique(['receipt_no']);
            $table->dropColumn(['tax_amount', 'tax_rate_snapshot', 'receipt_no', 'queue_no', 'guest_count', 'prebill_at', 'prebill_count']);
        });
    }
};
