<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 045-outlets-per-outlet-menu, Stage 6 — transfer stok antar outlet (dua langkah: kirim lalu terima).
 *
 * `stock_transfers` (nomor TRF-YYYYMMDD-####, outlet asal ≠ tujuan, status in_transit|received|cancelled, penanda selisih,
 * `client_ref` untuk idempoten) dan `stock_transfer_lines` (jenis variant|material, jumlah dikirim, jumlah diterima,
 * alasan selisih). Barang "dalam perjalanan" = Σ qty_sent baris transfer berstatus in_transit; selisih yang tercatat =
 * qty_sent − qty_received pada transfer received (invarian I3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('number', 30)->unique();
                $table->foreignId('from_outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignId('to_outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->enum('status', ['in_transit', 'received', 'cancelled'])->default('in_transit');
                $table->boolean('has_discrepancy')->default(false);
                $table->string('note', 255)->nullable();
                $table->string('client_ref', 64)->nullable()->unique();
                $table->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('dispatched_at')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->index(['status', 'dispatched_at'], 'idx_stock_transfers_status');
                $table->index('from_outlet_id', 'idx_stock_transfers_from');
                $table->index('to_outlet_id', 'idx_stock_transfers_to');
            });
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT chk_stock_transfers_outlets CHECK (from_outlet_id <> to_outlet_id)');
        }

        if (! Schema::hasTable('stock_transfer_lines')) {
            Schema::create('stock_transfer_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
                $table->enum('kind', ['variant', 'material']);
                $table->unsignedBigInteger('item_id');
                $table->decimal('qty_sent', 14, 3);
                $table->decimal('qty_received', 14, 3)->nullable();
                $table->string('discrepancy_reason', 255)->nullable();
                $table->timestamps();

                $table->index(['kind', 'item_id'], 'idx_stock_transfer_lines_item');
            });
            DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT chk_stock_transfer_lines_qty CHECK (qty_sent > 0 AND (qty_received IS NULL OR (qty_received >= 0 AND qty_received <= qty_sent)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
