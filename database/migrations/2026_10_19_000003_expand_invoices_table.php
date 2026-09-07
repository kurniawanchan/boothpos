<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 019-billing-system (research.md R4', data-model.md) — memperluas Invoice
 * dari catatan billing sederhana (satu `amount`) menjadi dokumen mandiri
 * penuh: nomor invoice, snapshot lisensi yang ditagih, dan rincian
 * subtotal/diskon/grand total. `grand_total` DISIMPAN (bukan dihitung saat
 * baca) supaya perubahan `licenses.price` di kemudian hari TIDAK PERNAH
 * mengubah invoice yang sudah terbit (FR-013).
 *
 * Baris invoice yang sudah ada (dari pass small-scope sebelumnya) di-backfill
 * DULU (subtotal = amount, grand_total = amount, discount = 0) SEBELUM kolom
 * `amount` di-drop, supaya tidak ada data yang hilang diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('id');
            $table->foreignId('license_id')->nullable()->after('company_id')
                ->constrained('licenses')->restrictOnDelete();
            $table->decimal('subtotal', 14, 2)->nullable()->after('license_id');
            $table->decimal('discount', 14, 2)->default(0)->after('subtotal');
            $table->decimal('grand_total', 14, 2)->nullable()->after('discount');
            $table->text('payment_information')->nullable()->after('grand_total');
        });

        // Backfill baris lama SEBELUM `amount` di-drop (data-model.md).
        DB::table('invoices')->whereNull('subtotal')->update([
            'subtotal' => DB::raw('amount'),
            'grand_total' => DB::raw('amount'),
            'discount' => 0,
        ]);

        // BUG YANG DITEMUKAN & DIPERBAIKI (019-billing-system) — baris
        // invoice lama (dari pass small-scope sebelumnya, termasuk yang
        // sempat dibuat sungguhan lewat browser saat verifikasi manual)
        // tidak punya invoice_number sama sekali. Kolom ini di bawah
        // di-set NOT NULL + UNIQUE, jadi wajib diisi dulu — dipakai
        // placeholder `INV-LEGACY-{id}` (unik per id, tidak akan pernah
        // bentrok dengan format INV-{YYYYMM}-{seq} yang dipakai invoice
        // baru) sebelum constraint NOT NULL diterapkan.
        DB::table('invoices')->whereNull('invoice_number')->update([
            'invoice_number' => DB::raw("CONCAT('INV-LEGACY-', id)"),
        ]);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('amount');
            $table->string('invoice_number')->nullable(false)->unique()->change();
            $table->decimal('subtotal', 14, 2)->nullable(false)->change();
            $table->decimal('grand_total', 14, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('amount', 14, 2)->nullable()->after('license_id');
        });

        DB::table('invoices')->update([
            'amount' => DB::raw('grand_total'),
        ]);

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('amount', 14, 2)->nullable(false)->change();
            $table->dropConstrainedForeignId('license_id');
            $table->dropColumn(['invoice_number', 'subtotal', 'discount', 'grand_total', 'payment_information']);
        });
    }
};
