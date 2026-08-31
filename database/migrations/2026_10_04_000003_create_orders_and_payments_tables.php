<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('session_id')->constrained('cashier_sessions')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('channel', ['offline', 'online'])->default('offline');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->enum('status', ['completed', 'voided'])->default('completed');
            $table->string('void_reason')->nullable();
            $table->uuid('local_ref')->unique()->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index('session_id');
            $table->index('customer_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->restrictOnDelete();
            $table->char('sku_snapshot', 12);
            $table->string('name_snapshot', 255);
            $table->integer('qty');
            $table->decimal('cost_price', 14, 2);
            $table->decimal('sell_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index('order_id');
            $table->index('variant_id');
            $table->index('artist_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('preorder_id')->nullable(); // FK ditambahkan di migration preorders
            $table->foreignId('channel_id')->nullable()->constrained('payment_channels')->restrictOnDelete();
            $table->enum('method', ['cash', 'bank_transfer', 'qr_ewallet']);
            $table->enum('purpose', ['full', 'down_payment', 'settlement'])->default('full');
            $table->decimal('amount', 14, 2);
            $table->enum('verification', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('preorder_id');
            $table->index(['method', 'verification']);
        });

        // CHECK constraint — MySQL 8+ menegakkan ini (lihat catatan
        // ASSUMPTION versi MySQL di schema-pos-mvp.sql).
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_qty CHECK (qty > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_target CHECK (
            (order_id IS NOT NULL AND preorder_id IS NULL) OR
            (order_id IS NULL AND preorder_id IS NOT NULL)
        )');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_channel CHECK (
            (method = 'cash' AND channel_id IS NULL) OR
            (method <> 'cash' AND channel_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
