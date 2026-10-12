<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 045-outlets-per-outlet-menu, Stage 3 (lapisan data) — menu per outlet.
 *
 *  - `outlet_item_settings`: relasi MATERIAL (outlet × produk) — apakah outlet itu menjual produknya.
 *    Setiap pasangan di mode data yang sama dibuatkan barisnya, `is_carried = true` (semua produk tersedia
 *    di semua outlet, keputusan pemilik Q1 = C); baris baru dibuat OutletMenuService saat produk/outlet dibuat.
 *  - `variant_outlet_prices`: harga per outlet yang JARANG — baris hanya ada bila harga outlet berbeda dari
 *    harga dasar varian (kosong = mengikuti dasar). Hanya ditulis PriceService.
 *
 * SENGAJA belum di sini: `item_availability_marks.outlet_id` NOT NULL dan pemecahan tanda 86 per outlet
 * (menyentuh AvailabilityService dan pemeriksaan pesanan) — langkah berikutnya, setelah tes pesanan siap.
 * Kolom `price_history.outlet_id` sudah ada (nullable; NULL = perubahan harga dasar).
 *
 * Re-runnable: tabel dibuat bila belum ada, baris diisi dengan insert-ignore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outlet_item_settings')) {
            Schema::create('outlet_item_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->boolean('is_carried')->default(true);
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->unique(['outlet_id', 'product_id'], 'uniq_outlet_item_settings_pair');
                $table->index(['product_id', 'is_carried'], 'idx_outlet_item_settings_product');
            });
        }

        if (! Schema::hasTable('variant_outlet_prices')) {
            Schema::create('variant_outlet_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->decimal('price', 14, 2);
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->unique(['variant_id', 'outlet_id'], 'uniq_variant_outlet_prices_pair');
            });
            DB::statement('ALTER TABLE variant_outlet_prices ADD CONSTRAINT chk_variant_outlet_prices_price CHECK (price >= 0)');
        }

        // Semua produk dibawa di semua outlet pada mode data yang sama (tanpa kehilangan produk yang sudah dijual).
        DB::statement(<<<'SQL'
            INSERT IGNORE INTO outlet_item_settings (outlet_id, product_id, is_carried, data_mode, created_at, updated_at)
            SELECT o.id, p.id, 1, p.data_mode, NOW(), NOW()
            FROM outlets o
            JOIN products p ON p.data_mode = o.data_mode
            WHERE o.deleted_at IS NULL AND p.deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_outlet_prices');
        Schema::dropIfExists('outlet_item_settings');
    }
};
