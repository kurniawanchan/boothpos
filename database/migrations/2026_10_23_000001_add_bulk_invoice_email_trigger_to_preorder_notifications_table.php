<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 022-preorder-invoice-crud-overhaul (US5, FR-014/FR-015) — bulk email
 * mengirim `PreorderInvoiceMail` (bukan `PreorderStatusMail`) per
 * pre-order terpilih, dicatat lewat trigger baru ini di
 * preorder_notifications — mengikuti pola audit yang sama persis dengan
 * `status_change`/`manual_resend` (research.md Decision 5), bukan bentuk
 * log baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE preorder_notifications MODIFY `trigger` ENUM('status_change', 'manual_resend', 'bulk_invoice_email') NOT NULL");

        Schema::table('preorder_notifications', function (Blueprint $table) {
            $table->string('document_type', 20)->nullable()->after('triggered_by_status');
        });
    }

    public function down(): void
    {
        Schema::table('preorder_notifications', function (Blueprint $table) {
            $table->dropColumn('document_type');
        });

        DB::statement("ALTER TABLE preorder_notifications MODIFY `trigger` ENUM('status_change', 'manual_resend') NOT NULL");
    }
};
