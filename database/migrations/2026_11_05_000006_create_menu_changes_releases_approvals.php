<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 043-products-page-redesign — mesin rilis menu dan persetujuan (research.md D2, D3, D9, D17). Dibangun
 * pada Tahap A dengan satu target ("seluruh instalasi"); Tahap B menambah target per outlet. Perubahan
 * yang menunggu rilis disimpan sebagai `menu_changes`; rilis menuliskannya ke kolom hidup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_releases')) {
            Schema::create('menu_releases', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('version');
                $table->enum('status', ['scheduled', 'published', 'partial', 'failed', 'cancelled'])->default('scheduled');
                $table->timestamp('publish_at');
                $table->timestamp('published_at')->nullable();
                $table->string('note', 255)->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->unique(['data_mode', 'version'], 'uniq_menu_releases_version');
                $table->index(['data_mode', 'status', 'publish_at'], 'idx_menu_releases_due');
            });
        }

        if (! Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 30);
                $table->string('subject_type', 40);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('decision', ['pending', 'approved', 'rejected'])->default('pending');
                $table->foreignId('reason_code_id')->nullable()->constrained('reason_codes')->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->decimal('difference_pct', 6, 2)->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->timestamp('decided_at')->nullable();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->index(['data_mode', 'kind', 'decision'], 'idx_approvals_queue');
                $table->index(['subject_type', 'subject_id'], 'idx_approvals_subject');
            });
        }

        if (! Schema::hasTable('price_change_batches')) {
            Schema::create('price_change_batches', function (Blueprint $table) {
                $table->id();
                $table->json('scope');
                $table->enum('type', ['percent', 'amount']);
                $table->decimal('value', 14, 4);
                $table->enum('rounding', ['none', '500'])->default('none');
                $table->date('effective_from');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('menu_changes')) {
            Schema::create('menu_changes', function (Blueprint $table) {
                $table->id();
                $table->enum('kind', ['price', 'item_publish', 'item_discontinue', 'item_reactivate', 'modifier_link']);
                $table->string('subject_type', 40);
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('outlet_id')->nullable();
                $table->json('payload');
                $table->date('effective_from')->nullable();
                $table->enum('status', ['pending', 'awaiting_approval', 'approved', 'rejected', 'released', 'cancelled'])->default('pending');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
                $table->foreignId('release_id')->nullable()->constrained('menu_releases')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('price_change_batches')->nullOnDelete();
                $table->enum('data_mode', ['demo', 'live'])->default('live');
                $table->timestamps();

                $table->index(['data_mode', 'status'], 'idx_menu_changes_status');
                $table->index(['subject_type', 'subject_id'], 'idx_menu_changes_subject');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_changes');
        Schema::dropIfExists('price_change_batches');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('menu_releases');
    }
};
