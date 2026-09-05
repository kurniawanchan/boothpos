<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 017-company-onboarding — daftar jenis bisnis yang bisa dipilih saat
 * onboarding company. SENGAJA BUKAN enum tetap: rencananya akan ada fitur
 * per-jenis-bisnis di masa depan (lihat spec.md), jadi daftar ini harus
 * bisa ditambah lewat UI tanpa migrasi skema setiap kali. Tidak
 * HasDataMode — ini data referensi/administratif yang dikelola admin,
 * kategori yang sama dengan payment_channels di CLAUDE.md, bukan data
 * bisnis/transaksional pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_types');
    }
};
