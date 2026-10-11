<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign (Q2 = B) — jenis bahan: bahan baku, setengah jadi, kemasan. `standard_cost`
 * dipakai untuk bahan setengah jadi yang tidak punya harga vendor (biaya resep, research.md D12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            if (! Schema::hasColumn('materials', 'kind')) {
                $table->enum('kind', ['ingredient', 'semi_finished', 'packaging'])->default('ingredient');
            }
            if (! Schema::hasColumn('materials', 'standard_cost')) {
                $table->decimal('standard_cost', 14, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['kind', 'standard_cost'] as $column) {
            if (Schema::hasColumn('materials', $column)) {
                Schema::table('materials', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
