<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 028 — hidangan made-to-order tidak punya stok fisik yang bisa dihitung.
 * Default TRUE supaya seluruh varian lama dan alur kasir ritel berperilaku
 * persis seperti sebelumnya (research.md Keputusan 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('track_stock')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('track_stock');
        });
    }
};
