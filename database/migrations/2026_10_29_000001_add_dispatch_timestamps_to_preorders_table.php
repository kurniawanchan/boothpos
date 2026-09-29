<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapan penanda `dispatch_status` diberi nilai "invoice terkirim" dan
 * "pengiriman berjalan" — ditampilkan di kolom Invoice/pengiriman pada list
 * dan di panel detail. Terpisah dari `updated_at`, yang ikut berubah pada
 * setiap edit lain sehingga tak bisa dipakai menjawab "kapan invoice dikirim".
 *
 * Diatur SERVER-side oleh PreorderController::updateDispatchStatus() (nilai
 * dari klien tidak pernah dipercaya); NULL = penanda itu belum/tidak aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->timestamp('invoice_sent_at')->nullable()->after('dispatch_status');
            $table->timestamp('shipping_at')->nullable()->after('invoice_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->dropColumn(['invoice_sent_at', 'shipping_at']);
        });
    }
};
