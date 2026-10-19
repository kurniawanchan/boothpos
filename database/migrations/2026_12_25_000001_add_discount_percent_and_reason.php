<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 057 / T005 — persentase dan alasan diskon disimpan di order (dan di baris bila diskon baris), bukan hanya di activity
 * log (keputusan produk, 2026-10-18). Kolom nullable; diskon yang sudah ada tidak di-backfill (FR-013).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount');
            $table->string('discount_reason_code', 40)->nullable()->after('discount_percent');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_amount');
            $table->string('discount_reason_code', 40)->nullable()->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_reason_code']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_reason_code']);
        });
    }
};
