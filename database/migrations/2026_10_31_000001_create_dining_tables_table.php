<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 028-restaurant-dine-in-ordering — meja makan (data-model.md).
 *
 * `area` NOT NULL dengan default '' (bukan nullable): UNIQUE di MySQL
 * memperlakukan setiap NULL sebagai nilai berbeda, jadi dua meja bernama
 * sama tanpa area akan lolos kalau kolom ini boleh NULL. '' = "tanpa area".
 *
 * Status "terisi/kosong" SENGAJA tidak disimpan di sini — ia diturunkan
 * dari ada-tidaknya order berstatus 'open' pada meja itu, supaya tidak
 * pernah bisa menyimpang dari kebenaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('area', 60)->default('');
            $table->unsignedSmallInteger('seats')->default(2);
            $table->smallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_dining_tables_data_mode');
            $table->timestamps();

            $table->unique(['data_mode', 'area', 'name'], 'uniq_dining_tables_mode_area_name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
