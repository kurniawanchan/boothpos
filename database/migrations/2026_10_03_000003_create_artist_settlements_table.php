<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->restrictOnDelete();
            $table->decimal('total_sales', 14, 2)->default(0);
            $table->integer('total_units')->default(0);
            $table->decimal('deduction', 14, 2)->default(0);
            $table->decimal('payable_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'artist_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_settlements');
    }
};
