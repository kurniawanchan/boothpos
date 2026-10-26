<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 076-settlement-tab-session-branding — modul settlement seller.
 *
 * Tabel BARU dengan awalan `seller_` karena `artist_settlements` sudah ada
 * (cache Rekap Seller yang POS-only sejak fitur 040) dan sengaja tidak
 * disentuh. Satu settlement = satu (seller, event); "periode bebas"
 * dibuang dari desain karena rentang tanggal bisa tumpang tindih dengan
 * event dan membuat transaksi yang sama ter-settle dua kali.
 *
 * Keseimbangan angka dijaga di TIGA lapis: pembulatan sekali per baris
 * sumber, rekonsiliasi independen yang harus 0.00 saat finalisasi, dan
 * CHECK di bawah ini (MySQL 8 saja, sama seperti CHECK migrasi lain).
 *
 * Tabel anak tidak punya `data_mode`: mode-nya ikut induk
 * (CloneRegistry 'via', pola yang sama dengan purchase_order_items).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlements', function (Blueprint $table) {
            $table->id();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_seller_settlements_data_mode');
            // Diisi saat finalisasi (draft = NULL). UNIQUE lintas mode.
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->restrictOnDelete();
            // Snapshot supaya dokumen tetap benar setelah rename/soft-delete.
            $table->string('event_name');
            $table->string('artist_name');
            $table->string('artist_code', 10)->nullable();
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            // 1 selama baris ini settlement aktif; UNIQUE di bawah membuat DB
            // menolak settlement aktif kedua untuk pasangan yang sama.
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->unsignedInteger('pos_units')->default(0);
            $table->decimal('pos_revenue', 14, 2)->default(0);
            $table->unsignedInteger('preorder_units')->default(0);
            $table->decimal('preorder_revenue', 14, 2)->default(0);
            $table->decimal('revenue_total', 14, 2)->default(0);
            $table->decimal('adjustments_total', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            // CACHE dari seller_settlement_payments, ditulis ulang di transaksi yang sama.
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('memo_preorder_uncollected', 14, 2)->default(0);
            $table->decimal('memo_cogs_pos', 14, 2)->default(0);
            $table->decimal('memo_cogs_preorder', 14, 2)->default(0);
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['artist_id', 'event_id', 'active_slot'], 'uq_seller_settlements_active');
            $table->index(['event_id', 'artist_id'], 'idx_seller_settlements_event_artist');
            $table->index('status', 'idx_seller_settlements_status');
        });

        Schema::create('seller_settlement_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('seller_settlements')->cascadeOnDelete();
            $table->enum('source_type', ['order', 'preorder']);
            // Tanpa FK: snapshot adalah kebenarannya, sumber boleh nanti di-void/dibatalkan.
            $table->unsignedBigInteger('source_id');
            $table->string('source_number', 30);
            $table->dateTime('source_date');
            $table->unsignedInteger('units')->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('uncollected_amount', 14, 2)->default(0);
            $table->decimal('cost_amount', 14, 2)->default(0);

            $table->unique(['settlement_id', 'source_type', 'source_id'], 'uq_sset_sources_row');
        });

        Schema::create('seller_settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('seller_settlements')->cascadeOnDelete();
            $table->enum('kind', ['deduction', 'adjustment']);
            $table->string('label', 120);
            $table->text('reason');
            $table->string('basis', 160)->nullable();
            // Pengaruh BERTANDA terhadap net (deduction disimpan negatif).
            $table->decimal('amount', 14, 2);
            $table->boolean('post_finalization')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('seller_settlement_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('seller_settlements')->cascadeOnDelete();
            $table->enum('method', ['signature', 'typed', 'external']);
            $table->string('signer_name', 120)->nullable();
            // Disk privat `local`, tidak pernah publik.
            $table->string('signature_path')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('acknowledged_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('superseded_at')->nullable();
            $table->enum('superseded_reason', ['replaced', 'reopened', 'adjusted'])->nullable();
            $table->timestamps();

            $table->index(['settlement_id', 'superseded_at'], 'idx_sset_ack_current');
        });

        Schema::create('seller_settlement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('seller_settlements')->cascadeOnDelete();
            // Idempotensi: UUID dari klien, UNIQUE (tidak ada kolom data_mode yang perlu diakali).
            $table->char('client_ref', 36)->unique();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->enum('method', ['cash', 'bank_transfer', 'ewallet', 'other']);
            $table->string('reference', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('proof_path')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE seller_settlements ADD CONSTRAINT chk_sset_revenue CHECK (revenue_total = pos_revenue + preorder_revenue)');
        DB::statement('ALTER TABLE seller_settlements ADD CONSTRAINT chk_sset_net CHECK (net_amount = revenue_total + adjustments_total)');
        DB::statement('ALTER TABLE seller_settlements ADD CONSTRAINT chk_sset_paid_nonneg CHECK (paid_amount >= 0)');
        DB::statement('ALTER TABLE seller_settlements ADD CONSTRAINT chk_sset_paid_cap CHECK (paid_amount <= ABS(net_amount))');
        DB::statement("ALTER TABLE seller_settlements ADD CONSTRAINT chk_sset_final CHECK (status = 'draft' OR (number IS NOT NULL AND finalized_at IS NOT NULL))");
        DB::statement("ALTER TABLE seller_settlement_adjustments ADD CONSTRAINT chk_sadj_amount CHECK (amount <> 0 AND (kind <> 'deduction' OR amount < 0))");
        DB::statement('ALTER TABLE seller_settlement_payments ADD CONSTRAINT chk_sset_payments_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlement_payments');
        Schema::dropIfExists('seller_settlement_acknowledgements');
        Schema::dropIfExists('seller_settlement_adjustments');
        Schema::dropIfExists('seller_settlement_sources');
        Schema::dropIfExists('seller_settlements');
    }
};
