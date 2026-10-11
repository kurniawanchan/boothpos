<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 042-dashboard-revamp — harga satuan pada saat tiket dikirim ke dapur/bar.
 *
 * "Void setelah fire" di Dashboard harus menampilkan nilai. Baris order yang dibatalkan setelah
 * dikirim DIHAPUS, dan `kitchen_ticket_items` hanya menyimpan nama + qty, jadi nilainya tidak
 * bisa dipulihkan dari tempat lain; membaca harga varian SEKARANG akan menulis ulang riwayat
 * bila harga berubah. Maka harga disalin saat kirim (snapshot, Konstitusi IV).
 *
 * Tidak ada backfill: pembatalan lama tampil sebagai "nilai tidak diketahui", bukan angka karangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('kitchen_ticket_items', 'unit_price')) {
            return;
        }

        Schema::table('kitchen_ticket_items', function (Blueprint $table) {
            $table->decimal('unit_price', 14, 2)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('kitchen_ticket_items', 'unit_price')) {
            Schema::table('kitchen_ticket_items', fn (Blueprint $t) => $t->dropColumn('unit_price'));
        }
    }
};
