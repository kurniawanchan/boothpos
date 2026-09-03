<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 006-purchase-order-and-ops (US1) — membalik pencoretan PRD §10.2
 * "purchase management (PO to vendors)" atas permintaan eksplisit pemilik
 * produk (2026-09-03), sama seperti modul vendor/material/BOM sendiri
 * dibalik dari pencoretannya pada 2026-09-01. Lihat spec.md Assumptions
 * dan research.md untuk rasionalnya.
 *
 * `data_mode` disertakan langsung di CREATE (bukan migrasi ALTER
 * terpisah) karena ini tabel baru — lihat CLAUDE.md "Seed data dan
 * DEMO/LIVE mode": setiap model data bisnis/transaksional baru WAJIB
 * memakai HasDataMode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 30)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->enum('status', ['draft', 'ordered', 'received', 'paid', 'cancelled'])->default('draft');
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_purchase_orders_data_mode');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index('status');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->enum('line_type', ['material', 'service']);
            // material_id WAJIB saat line_type=material, product_id SELALU
            // opsional apa pun line_type-nya (FR-004) — divalidasi di
            // StorePurchaseOrderRequest, bukan di skema (keduanya nullable
            // di sini karena keduanya memang boleh kosong tergantung baris).
            $table->foreignId('material_id')->nullable()->constrained('materials')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('qty', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
