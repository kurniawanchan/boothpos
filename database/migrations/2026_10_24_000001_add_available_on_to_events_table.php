<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 023-event-availability-invoice-redesign (US1) — pilihan mentah
 * ('day_1'/'day_2'), BUKAN tanggal asli — diselesaikan ke tanggal nyata
 * lewat Event::availableOnDate() setiap kali dibaca, supaya edit tanggal
 * event tidak pernah membuat nilai ini basi (research.md Decision 1,
 * sama seperti alasan preorders.pickup_day TIDAK dipakai polanya di sini
 * — pickup_day milik pelanggan per-pesanan, available_on milik event itu
 * sendiri dan harus selalu mengikuti tanggal event yang sedang berlaku).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->enum('available_on', ['day_1', 'day_2'])->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('available_on');
        });
    }
};
