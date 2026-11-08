<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 064 — persetujuan massal. `bulk_ref` = UUID yang dibagi semua persetujuan hasil SATU aksi massal; NULL =
 * persetujuan tunggal (semua baris lama, tanpa backfill). Sengaja kolom, bukan tabel batch: identitas + hitungan
 * sudah dicukupi referensi ini dan activity log, tanpa `HasDataMode`/entri CloneRegistry baru (research D4).
 * Tidak unik dan tak punya FK — hanya penanda yang dibaca riwayat owner/admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_approvals', function (Blueprint $table) {
            $table->char('bulk_ref', 36)->nullable()->after('active_slot');
            $table->index('bulk_ref', 'idx_order_approvals_bulk_ref');
        });
    }

    public function down(): void
    {
        Schema::table('order_approvals', function (Blueprint $table) {
            $table->dropIndex('idx_order_approvals_bulk_ref');
            $table->dropColumn('bulk_ref');
        });
    }
};
