<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambah kolom `address` ke tabel customers — field PII
     * (seperti phone/email/social_handle) yang tidak pernah diekspor ke
     * artist (lihat CustomerResource + Policy). Nullable karena data
     * pelanggan yang sudah ada tidak semuanya punya alamat.
     *
     * Di-apply via php artisan migrate di kedua path deployment (Docker
     * store + native). Urutan date-prefix jelas setelah semua migration
     * existing (terakhir: 2026_10_19_000011), jadi tidak ada dependensi
     * FK yang terganggu — ini hanya ALTER TABLE plain.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('address')->nullable()->after('social_handle');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};