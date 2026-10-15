<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 031-optional-payment-proof (research Decision 3) — mengganti bukti yang
     * sudah ada pada sebuah pembayaran TIDAK menghapus file/barisnya: baris lama
     * ditandai `superseded_at`, file tetap ada di disk privat, dan activity log
     * menunjuk ke bukti aslinya (audit). "Bukti yang berlaku" = baris yang belum
     * ter-supersede.
     *
     * Sengaja kolom timestamp, BUKAN memutus tautan (payment_id = NULL): baris
     * tanpa payment_id sudah punya arti lain di tabel ini (bukti yang diunggah
     * lebih dulu dan menunggu token dipakai; aman dibersihkan setelah 24 jam),
     * sehingga bukti lama yang dilepas tak bisa dibedakan darinya dan riwayatnya
     * tak bisa ditelusuri.
     */
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
