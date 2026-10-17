<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda SKU yang diketik pemilik (spec 045). Varian lama dan SKU yang dibuat
 * server tetap bernilai false; hanya VariantSkuService yang menyetelnya true.
 * Dipakai ProductSegmentChangeService agar SKU yang diketik tidak ikut
 * diregenerasi saat segmen produk berubah (Q2 A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('sku_is_typed')->default(false)->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('sku_is_typed');
        });
    }
};
