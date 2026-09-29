<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda manual "invoice sudah dikirim" / "pengiriman sedang berjalan".
 *
 * Sengaja kolom TERPISAH dari `preorders.status` (state machine stok/
 * pembayaran: ordered → dp_paid → arrived → settled → handed_over) dan dari
 * `shipments.status`: `shipments` baru ada setelah data kurir dicatat,
 * sedangkan owner butuh menandai "invoice sudah kirim" jauh sebelum itu —
 * dan pre-order pickup tidak punya baris shipment sama sekali. Mengubah nilai
 * ini TIDAK PERNAH menyentuh stok/pembayaran/status utama.
 *
 * Default 'pending' (bukan NULL) supaya filter whereIn() dan kolom di list
 * tidak perlu menangani dua bentuk "belum ada" sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->enum('dispatch_status', ['pending', 'invoice_sent', 'shipping'])
                ->default('pending')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->dropColumn('dispatch_status');
        });
    }
};
