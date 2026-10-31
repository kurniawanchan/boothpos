<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 087 — bahan yang dipakai sebuah pilihan modifier, per satu porsi (research D1).
 *
 * Baris komponen, bukan kolom di `modifier_options`: satu pilihan boleh memakai lebih dari satu bahan
 * (mis. "Extra Pedas" = cabai bubuk + saus sambal). Bentuknya sengaja sama dengan `recipe_lines`
 * (bahan + jumlah) supaya jalur potong bisa dipakai resep menu kelak. Saldo bahan TIDAK disimpan di sini:
 * satu bahan tetap satu saldo per outlet (`material_outlet_stock`), berapa pun pilihan yang merujuknya.
 *
 * Ber-`data_mode` (aturan 003): ini model bisnis baru yang ditulis pengguna, bukan pivot murni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_option_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_option_id')->constrained('modifier_options')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_modifier_option_materials_data_mode');
            $table->timestamps();

            $table->unique(['modifier_option_id', 'material_id'], 'uniq_modifier_option_material');
            $table->index('material_id', 'idx_modifier_option_materials_material');
        });

        DB::statement('ALTER TABLE modifier_option_materials ADD CONSTRAINT chk_modifier_option_materials_qty_pos CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_option_materials');
    }
};
