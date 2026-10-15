<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 034-seller-po-bom — penanda "BOM selesai" per varian. Selama true,
 * cost_price varian mengikuti biaya BOM otomatis dan dikunci terhadap
 * edit manual (ditegakkan di VariantBomService/ProductController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('bom_complete')->default(false);
            $table->timestamp('bom_completed_at')->nullable();
            $table->foreignId('bom_completed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bom_completed_by');
            $table->dropColumn(['bom_complete', 'bom_completed_at']);
        });
    }
};
