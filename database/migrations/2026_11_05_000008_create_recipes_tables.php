<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign (Q3 = B) — resep berversi, berdampingan dengan BOM berbasis PO (research.md
 * D12). Sebuah versi tidak pernah diubah setelah dibuat: perubahan = versi baru. Satuan baris = satuan bahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipes')) {
            Schema::create('recipes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->decimal('yield', 10, 3);
                $table->date('effective_from');
                $table->text('note')->nullable();
                // Kapan biaya versi ini DITERAPKAN ke cost_price varian (saat berlaku / hitung ulang). NULL = belum.
                $table->timestamp('cost_applied_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->unique(['product_id', 'version'], 'uniq_recipes_product_version');
                $table->index(['product_id', 'effective_from'], 'idx_recipes_effective');
            });
        }

        if (! Schema::hasTable('recipe_variants')) {
            Schema::create('recipe_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->unique(['recipe_id', 'variant_id'], 'uniq_recipe_variants');
            });
        }

        if (! Schema::hasTable('recipe_lines')) {
            Schema::create('recipe_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
                $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
                $table->decimal('quantity', 12, 4);
                $table->decimal('shrinkage_pct', 5, 2)->default(0);
                $table->unsignedSmallInteger('display_order')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
        Schema::dropIfExists('recipe_variants');
        Schema::dropIfExists('recipes');
    }
};
