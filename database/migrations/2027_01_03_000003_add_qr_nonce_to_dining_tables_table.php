<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 090-self-service-qr-ordering — `qr_nonce` ikut ditandatangani di token QR meja. Memutarnya ("Buat ulang QR")
 * mematikan QR lama tanpa mengubah id meja, jadi QR yang bocor bisa dicabut.
 *
 * Diisi acak untuk SEMUA baris lama, termasuk meja bermode DEMO dan LIVE dan meja nonaktif/terhapus lunak
 * (tanpa global scope, DB::table langsung).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->char('qr_nonce', 16)->default('')->after('is_active');
        });

        DB::table('dining_tables')->orderBy('id')->select('id')->each(function ($row) {
            DB::table('dining_tables')->where('id', $row->id)->update(['qr_nonce' => Str::random(16)]);
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropColumn('qr_nonce');
        });
    }
};
