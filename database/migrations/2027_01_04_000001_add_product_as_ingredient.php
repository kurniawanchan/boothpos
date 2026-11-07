<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 093 — produk sebagai bahan resep dengan SATU saldo bersama (research D1, D2, D4).
 *
 *  - `products.use_as_ingredient` / `ingredient_unit`: penanda tingkat produk + label satuan tampilan.
 *  - `recipe_lines.variant_id`: baris resep boleh merujuk varian produk (saldo `variant_outlet_stock` yang sudah ada)
 *    sebagai ganti bahan baku. Satu kolom polimorfik, bukan tabel baris kedua, supaya urutan, blok, potret, dan
 *    penyajian resep tetap SATU. `material_id` menjadi nullable; CHECK menjaga TEPAT SATU yang terisi. Kedua FK
 *    RESTRICT, sehingga MySQL mengizinkan CHECK pada kolom tersebut (CASCADE/SET NULL tidak).
 *  - `stock_movements.type`: tiga tipe pemakaian resep supaya riwayat membedakannya dari penjualan langsung dan
 *    tidak bertabrakan dengan makna `reference_id` pada `sale` (id ORDER di sebagian jalur, id BARIS di jalur lain).
 *
 * Tidak mengubah satu pun baris data: resep lama tetap berbahan baku, angka stok tetap.
 */
return new class extends Migration
{
    private const TYPES_BEFORE = "'purchase','sale','preorder_handover','adjustment','return','initial','transfer_out','transfer_in','transfer_cancel'";

    private const TYPES_AFTER = "'purchase','sale','preorder_handover','adjustment','return','initial','transfer_out','transfer_in','transfer_cancel','recipe_sale','recipe_return','recipe_waste'";

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('use_as_ingredient')->default(false)->after('stock_source');
            $table->string('ingredient_unit', 20)->nullable()->after('use_as_ingredient');
        });

        DB::statement('ALTER TABLE recipe_lines MODIFY material_id BIGINT UNSIGNED NULL');
        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('material_id')->constrained('product_variants')->restrictOnDelete();
            $table->index('variant_id', 'idx_recipe_lines_variant');
        });
        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT chk_recipe_lines_one_ingredient CHECK ((material_id IS NULL) <> (variant_id IS NULL))');

        DB::statement('ALTER TABLE stock_movements MODIFY type ENUM('.self::TYPES_AFTER.') NOT NULL');
    }

    public function down(): void
    {
        // Memotong enum atau kolom dengan baris hidup merusak data; tolak dengan jelas (preseden 087).
        $moves = DB::table('stock_movements')->whereIn('type', ['recipe_sale', 'recipe_return', 'recipe_waste'])->count();
        if ($moves > 0) {
            throw new RuntimeException("Tidak bisa rollback 093: masih ada {$moves} pergerakan stok berjenis recipe_*.");
        }
        $lines = DB::table('recipe_lines')->whereNotNull('variant_id')->count();
        if ($lines > 0) {
            throw new RuntimeException("Tidak bisa rollback 093: masih ada {$lines} baris resep yang merujuk produk bahan.");
        }

        DB::statement('ALTER TABLE stock_movements MODIFY type ENUM('.self::TYPES_BEFORE.') NOT NULL');

        DB::statement('ALTER TABLE recipe_lines DROP CHECK chk_recipe_lines_one_ingredient');
        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropIndex('idx_recipe_lines_variant');
            $table->dropColumn('variant_id');
        });
        DB::statement('ALTER TABLE recipe_lines MODIFY material_id BIGINT UNSIGNED NOT NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['use_as_ingredient', 'ingredient_unit']);
        });
    }
};
