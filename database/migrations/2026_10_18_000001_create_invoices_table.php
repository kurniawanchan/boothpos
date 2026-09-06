<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 019-billing-system — catatan billing manual per company (017), tanpa
 * integrasi payment gateway. `data_mode` disertakan langsung di CREATE
 * (tabel baru) — invoice adalah data bisnis/transaksional (setara
 * Customer/Order), berbeda dari Package/BusinessType yang administratif
 * (research.md R3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            // Snapshot tetap saat dibuat — TIDAK PERNAH dihitung ulang dari
            // package company saat ini (research.md R4).
            $table->decimal('amount', 14, 2);
            $table->date('due_date');
            $table->enum('status', ['unpaid', 'paid', 'cancelled'])->default('unpaid');
            $table->date('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_invoices_data_mode');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
