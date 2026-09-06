<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 017-company-onboarding — audit trail per percobaan kirim kode aktivasi,
 * mencerminkan persis bentuk preorder_notifications (research.md R3).
 * SENGAJA BUKAN HasDataMode — metadata operasional tentang tindakan
 * instalasi ini sendiri (siapa mencoba mengirim apa, kapan, berhasil
 * atau tidak), kategori yang sama dengan activity_logs/payment_channels/
 * preorder_notifications di CLAUDE.md, bukan data bisnis/transaksional
 * yang tunduk batas DEMO/LIVE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_activation_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->enum('trigger', ['created', 'resend']);
            $table->string('recipient_email', 150);
            $table->enum('status', ['sent', 'skipped_not_configured', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_activation_notifications');
    }
};
