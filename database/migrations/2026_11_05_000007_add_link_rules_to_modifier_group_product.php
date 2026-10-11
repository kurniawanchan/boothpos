<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — aturan per TAUTAN item↔grup modifier (wajib, min, maks, urutan; research.md D10).
 * `has_rules` menandai bahwa tautan MEMBAWA aturannya sendiri: false (bawaan) = pakai nilai grup, sehingga perilaku
 * 028 tidak berubah dan `max_select` NULL pada tautan berarti "tanpa batas atas", bukan "ikut grup".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_group_product', function (Blueprint $table) {
            if (! Schema::hasColumn('modifier_group_product', 'has_rules')) {
                $table->boolean('has_rules')->default(false);
            }
            if (! Schema::hasColumn('modifier_group_product', 'is_required')) {
                $table->boolean('is_required')->nullable();
            }
            if (! Schema::hasColumn('modifier_group_product', 'min_select')) {
                $table->unsignedTinyInteger('min_select')->nullable();
            }
            if (! Schema::hasColumn('modifier_group_product', 'max_select')) {
                $table->unsignedTinyInteger('max_select')->nullable();
            }
            if (! Schema::hasColumn('modifier_group_product', 'display_order')) {
                $table->unsignedSmallInteger('display_order')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['has_rules', 'is_required', 'min_select', 'max_select', 'display_order'] as $column) {
            if (Schema::hasColumn('modifier_group_product', $column)) {
                Schema::table('modifier_group_product', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
