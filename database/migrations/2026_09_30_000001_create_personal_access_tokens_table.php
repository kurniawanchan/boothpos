<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dibawa dari `php artisan install:api` (Laravel Sanctum) saat bootstrap
 * skeleton — bukan bagian dari schema-pos-mvp.sql, yang memang tidak
 * mendokumentasikan tabel token karena skema itu ditulis sebelum
 * mekanisme auth API dipilih. Diberi tanggal SEBELUM
 * 2026_10_01_000000_create_users_table.php (bukan tanggal asli Sanctum)
 * supaya mengikuti konvensi "tabel infrastruktur duluan" yang sama
 * dipakai skeleton bawaan Laravel (users+cache+jobs sebelum tabel app).
 * Aman ditaruh sebelum users karena tokenable_id/type bersifat
 * polimorfik (morphs), tidak ada FK keras ke tabel users.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
