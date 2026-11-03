<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 090-self-service-qr-ordering — penanda asal order. `source = self_service` permanen (FR-015); bawaan `pos`
 * supaya setiap jalur lama tidak berubah. Kolom TIDAK fillable: hanya SelfServiceOrderService yang mengisinya
 * (forceFill), sehingga klien POS staf tidak bisa memalsukannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('source', ['pos', 'self_service'])->default('pos')->after('service_type');
            $table->unsignedBigInteger('self_service_order_id')->nullable()->after('source');
            $table->index(['source', 'business_date'], 'idx_orders_source_business_date');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('self_service_order_id', 'fk_orders_self_service_order')->references('id')->on('self_service_orders')->nullOnDelete();
        });

        Schema::table('self_service_orders', function (Blueprint $table) {
            $table->foreign('order_id', 'fk_self_service_orders_order')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('self_service_orders', function (Blueprint $table) {
            $table->dropForeign('fk_self_service_orders_order');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('fk_orders_self_service_order');
            $table->dropIndex('idx_orders_source_business_date');
            $table->dropColumn(['source', 'self_service_order_id']);
        });
    }
};
