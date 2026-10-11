<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — tanda "86" (habis): sementara, berakhir otomatis di akhir hari bisnis.
 * `expires_at` dibandingkan saat dibaca (tanpa job). `outlet_id` nullable: NULL = seluruh instalasi
 * (Tahap A); Tahap B mengisinya dengan outlet bawaan lalu menjadikannya wajib (data-model.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_availability_marks')) {
            return;
        }

        Schema::create('item_availability_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->foreignId('reason_code_id')->constrained('reason_codes')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->timestamp('expires_at');
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('data_mode', ['demo', 'live'])->default('live');
            $table->timestamps();

            $table->index(['data_mode', 'expires_at', 'cleared_at'], 'idx_marks_mode_expiry');
            $table->index(['product_id', 'cleared_at'], 'idx_marks_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_availability_marks');
    }
};
