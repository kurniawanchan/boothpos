<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 019-billing-system — rename `packages` -> `licenses` (permintaan
 * eksplisit product owner: "ubah manajemen package menjadi license",
 * research.md R1'). Ini RENAME entitas yang sudah ada, BUKAN entitas
 * paralel baru — menyimpan keduanya akan menghasilkan dua sumber
 * kebenaran untuk data yang sama (nama/deskripsi paket sebuah company).
 *
 * `price` dan `payment_type` baru ditambahkan di migration yang sama
 * supaya "rename" dan "tambah kolom" selesai dalam satu langkah yang
 * bisa di-rollback bersamaan — `payment_type` murni label deskriptif
 * (FR-017): TIDAK ADA billing/recurring otomatis di balik nilai
 * 'subscription'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('packages', 'licenses');

        Schema::table('licenses', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->default(0)->after('license_tier');
            $table->enum('payment_type', ['one_time', 'subscription'])->default('one_time')->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['price', 'payment_type']);
        });

        Schema::rename('licenses', 'packages');
    }
};
