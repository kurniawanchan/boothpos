<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 086 — pengecualian per item: sebuah item boleh MELEPAS grup modifier yang diwarisi dari kategorinya.
 *
 * Sengaja tabel terpisah, BUKAN kolom di `modifier_group_product`: banyak pembaca memakai `Product::modifierGroups()`
 * sebagai "tautan item" (penjaga hapus grup, lampiran, daftar), jadi baris "dikecualikan" di tabel itu akan dibaca
 * sebagai tautan sungguhan. Tabel ini hanya dibaca `ModifierResolver::inheritedGroups`. Tanpa data_mode, seperti tabel
 * pivot lain: dijangkau lewat induk (produk, grup) yang sudah ber-mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_group_product_exclusions', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['product_id', 'modifier_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_group_product_exclusions');
    }
};
