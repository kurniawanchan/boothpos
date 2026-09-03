<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 006-purchase-order-and-ops (US1) — mencerminkan struktur stock_movements
 * (riwayat append-only, kolom stock_before/stock_after) tapi diikat ke
 * Material, bukan ProductVariant. Satu-satunya `type` yang ditulis fitur
 * ini adalah 'purchase' (dipicu PurchaseOrder berstatus Received); nilai
 * lain (mis. 'adjustment' untuk koreksi manual) sengaja belum ditambahkan
 * karena di luar cakupan spec ini — lihat research.md R4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->enum('type', ['purchase']);
            $table->decimal('qty_change', 12, 3);
            $table->decimal('stock_before', 12, 3);
            $table->decimal('stock_after', 12, 3);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_material_stock_movements_data_mode');
            $table->timestamp('created_at')->nullable();

            $table->index(['material_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_stock_movements');
    }
};
