<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur 022: kolom city/postal_code dihapus dari shipments — alamat
 * sekarang cukup satu field (address_line), mengikuti pola yang sama
 * dengan Customer.address (fitur 020). Data lama pada kedua kolom ini
 * tidak dimigrasikan ke address_line secara otomatis (spec.md fitur 022:
 * staf mengisi ulang alamat lengkap saat membuat data pengiriman).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['city', 'postal_code']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('city', 100)->after('address_line');
            $table->string('postal_code', 10)->nullable()->after('province');
        });
    }
};
