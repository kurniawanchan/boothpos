<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 007-preorder-import-export-notify (US4) — audit trail per percobaan
 * kirim email notifikasi pre-order (research.md R6). SENGAJA BUKAN
 * HasDataMode: ini metadata operasional tentang tindakan toko sendiri
 * (siapa mencoba mengirim apa, kapan, berhasil atau tidak), kategori yang
 * sama dengan `activity_logs`/`payment_channels` di CLAUDE.md — bukan
 * data bisnis/transaksional pelanggan yang tunduk batas DEMO/LIVE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorder_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preorder_id')->constrained('preorders')->cascadeOnDelete();
            $table->enum('trigger', ['status_change', 'manual_resend']);
            $table->string('triggered_by_status', 30)->nullable();
            $table->string('recipient_email')->nullable();
            $table->enum('status', ['sent', 'skipped_no_email', 'skipped_not_configured', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('preorder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_notifications');
    }
};
