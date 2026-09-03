<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 006-purchase-order-and-ops (US1/FR-005 payment) — reuse `payments`
 * (dan PaymentRecorder) untuk pembayaran Purchase Order lewat kolom
 * nullable baru, pola yang sama persis dengan order_id/preorder_id yang
 * sudah ada, BUKAN tabel/model pembayaran baru (Constitution I — satu
 * jalur sah pencatatan pembayaran). chk_payments_target sebelumnya XOR
 * dua kolom; diganti menjadi XOR tiga kolom (persis satu dari
 * order_id/preorder_id/purchase_order_id yang terisi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('preorder_id')
                ->constrained('purchase_orders')->restrictOnDelete();
            $table->index('purchase_order_id');
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_target');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_target CHECK (
            (order_id IS NOT NULL AND preorder_id IS NULL AND purchase_order_id IS NULL) OR
            (order_id IS NULL AND preorder_id IS NOT NULL AND purchase_order_id IS NULL) OR
            (order_id IS NULL AND preorder_id IS NULL AND purchase_order_id IS NOT NULL)
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT chk_payments_target');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_target CHECK (
            (order_id IS NOT NULL AND preorder_id IS NULL) OR
            (order_id IS NULL AND preorder_id IS NOT NULL)
        )');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
