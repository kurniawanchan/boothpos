<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->string('preorder_number', 30)->unique();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['ordered', 'dp_paid', 'arrived', 'settled', 'handed_over', 'cancelled'])->default('ordered');
            $table->enum('fulfillment', ['pickup', 'courier'])->default('pickup');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->date('expected_date')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('customer_id');
            $table->index('event_id');
        });

        Schema::create('preorder_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preorder_id')->constrained('preorders')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->restrictOnDelete();
            $table->char('sku_snapshot', 12);
            $table->string('name_snapshot', 255);
            $table->integer('qty');
            $table->decimal('cost_price', 14, 2);
            $table->decimal('sell_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index('preorder_id');
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preorder_id')->unique()->constrained('preorders')->restrictOnDelete();
            $table->string('courier_name', 50);
            $table->string('tracking_number', 50)->nullable();
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 30);
            $table->string('address_line');
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->enum('status', ['pending', 'packed', 'shipped', 'delivered'])->default('pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // FK payments.preorder_id ditunda sampai tabel preorders ada
        // (lihat migration create_orders_and_payments_tables).
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('preorder_id')->references('id')->on('preorders')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE preorder_items ADD CONSTRAINT chk_po_items_qty CHECK (qty > 0)');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['preorder_id']);
        });
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('preorder_items');
        Schema::dropIfExists('preorders');
    }
};
