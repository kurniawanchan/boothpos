<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sebelumnya gambar hanya ada di level PRODUK (products.image_path) —
 * semua variannya berbagi satu gambar yang sama. Diminta secara eksplisit
 * agar tiap varian (mis. tiap desain/motif dalam satu produk) bisa punya
 * gambarnya sendiri, jadi kolom ini genap sejajar dengan products.image_path,
 * bukan menggantikannya — gambar produk tetap ada untuk konteks di mana
 * hanya produk (bukan varian tertentu) yang relevan (mis. kartu produk di
 * layar Kelola Produk sebelum varian dipilih).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
