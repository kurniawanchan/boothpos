<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->char('sku', 12)->unique();
            $table->string('variant_name', 100)->default('Standard');
            $table->decimal('cost_price', 14, 2)->default(0);
            $table->decimal('sell_price', 14, 2);
            $table->integer('current_stock')->default(0);
            $table->integer('low_stock_alert')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
