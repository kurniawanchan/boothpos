<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 028 US3 — grup modifier, opsi (selisih harga bertanda), dan lampirannya ke
 * produk/kategori. Grup & opsi memakai data_mode (DEMO/LIVE); tabel pivot
 * tidak — keduanya hanya dijangkau lewat induk yang sudah ber-mode.
 *
 * Opsi TIDAK dihapus ketika diganti (dinonaktifkan): snapshot di baris order
 * sudah menyimpan nama/harganya, tetapi grup yang pernah dipakai harus tetap
 * ada untuk penjagaan hapus (ModifierGroupService::delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->nullable(); // NULL = tanpa batas atas (hanya jamak)
            $table->smallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_modifier_groups_data_mode');
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->restrictOnDelete();
            $table->string('name', 100);
            $table->decimal('price_delta', 14, 2)->default(0); // bertanda: positif, nol, atau negatif
            $table->smallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_modifier_options_data_mode');
            $table->timestamps();

            $table->index('modifier_group_id');
        });

        Schema::create('modifier_group_product', function (Blueprint $table) {
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['modifier_group_id', 'product_id']);
        });

        Schema::create('modifier_group_category', function (Blueprint $table) {
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['modifier_group_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_group_category');
        Schema::dropIfExists('modifier_group_product');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
    }
};
