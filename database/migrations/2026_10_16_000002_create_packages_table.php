<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 017-company-onboarding — paket yang bisa dipilih saat onboarding
 * company: nama/deskripsi (sisi billing) + license_tier (sisi lisensi,
 * setara Pro/Master yang sudah ada lewat multi_artist_enabled/
 * LicenseGate). PENTING (research.md R4) — license_tier di sini HANYA
 * data deskriptif tentang company yang bersangkutan; TIDAK PERNAH
 * diterapkan otomatis ke Setting multi_artist_enabled instalasi ini,
 * karena itu satu nilai GLOBAL untuk instalasi ini sendiri — menimpanya
 * per company yang di-onboarding akan diam-diam mengubah lisensi
 * instalasi untuk company lain yang sudah pakai instalasi yang sama.
 * Tidak HasDataMode — data referensi/administratif (seperti
 * payment_channels), bukan data bisnis/transaksional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('license_tier', ['pro', 'master']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
