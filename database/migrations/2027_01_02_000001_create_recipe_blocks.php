<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-02 (permintaan pemilik) — satu resep punya BEBERAPA tabel bahan; tiap tabel terikat pada SATU varian dan
 * (opsional) SATU pilihan modifier: tabel dengan pilihan hanya dipotong bila pilihan itu dipesan, tabel tanpa pilihan
 * = resep dasar varian itu. Tabel disebut "blok" (`recipe_blocks`); baris bahan kini milik blok (`recipe_lines.block_id`).
 *
 * Data lama: satu resep dengan N varian dulu berbagi satu daftar bahan. Kini tiap varian mendapat blok dasarnya
 * sendiri dengan SALINAN baris bahan (varian pertama memakai baris aslinya). Tak ada kehilangan data; `recipe_variants`
 * tetap ada sebagai himpunan varian tiap resep (dijaga RecipeService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained('modifier_options')->cascadeOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            // NULL tidak dianggap kembar oleh MySQL; pasangan (varian, pilihan) unik dijaga RecipeService.
            $table->index(['recipe_id', 'variant_id'], 'idx_recipe_blocks_recipe_variant');
        });

        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->foreignId('block_id')->nullable()->after('recipe_id')->constrained('recipe_blocks')->cascadeOnDelete();
        });

        $order = 0;
        foreach (DB::table('recipes')->orderBy('id')->pluck('id') as $recipeId) {
            $variantIds = DB::table('recipe_variants')->where('recipe_id', $recipeId)->orderBy('variant_id')->pluck('variant_id');
            $lines = DB::table('recipe_lines')->where('recipe_id', $recipeId)->orderBy('display_order')->orderBy('id')->get();
            foreach ($variantIds->values() as $i => $variantId) {
                $blockId = DB::table('recipe_blocks')->insertGetId(['recipe_id' => $recipeId, 'variant_id' => $variantId, 'modifier_option_id' => null, 'display_order' => ++$order]);
                foreach ($lines as $line) {
                    if ($i === 0) {
                        DB::table('recipe_lines')->where('id', $line->id)->update(['block_id' => $blockId]);
                    } else {
                        DB::table('recipe_lines')->insert([
                            'recipe_id' => $recipeId, 'block_id' => $blockId, 'material_id' => $line->material_id,
                            'quantity' => $line->quantity, 'shrinkage_pct' => $line->shrinkage_pct, 'display_order' => $line->display_order,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('block_id');
        });
        Schema::dropIfExists('recipe_blocks');
    }
};
