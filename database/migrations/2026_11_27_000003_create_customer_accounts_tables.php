<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer commerce (2026-11-11) — identitas pelanggan untuk aplikasi Android.
 *
 * `customer_accounts`: subjek token Sanctum milik pelanggan, SATU-ke-SATU
 * dengan `customers` (kontak yang dikelola staf). Sengaja tabel sendiri,
 * bukan kolom kata sandi di `customers` dan bukan baris `users`: kontak staf
 * tidak boleh bisa masuk, dan token pelanggan tidak boleh pernah dianggap
 * token staf.
 *  - `customer_id` RESTRICT: menghapus kontak yang punya akun harus gagal
 *    keras (termasuk saat wipe DEMO), bukan diam-diam menghapus akunnya.
 *  - `email` disimpan sudah di-trim dan huruf kecil; unik per mode data
 *    (`email`, `data_mode`) supaya uji coba registrasi di DEMO tidak mengunci
 *    e-mail yang sama di LIVE — sejalan dengan pencocokan e-mail pelanggan
 *    yang memang per mode (CustomerExportImportService).
 *  - `data_mode` langsung di CREATE: akun dicari langsung (login lewat
 *    e-mail), jadi harus ikut batas DEMO/LIVE.
 *
 * `customer_addresses`: alamat tersimpan milik akun. Tanpa `data_mode` —
 * hanya dijangkau lewat akunnya (pola `via` milik order_reference_images).
 * "Paling banyak satu alamat utama" dijaga service dalam transaksi, bukan
 * constraint DB (MySQL tidak punya indeks unik parsial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('email', 100);
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('disabled_at')->nullable();   // terisi = tidak bisa masuk, semua tokennya dicabut
            $table->timestamp('last_login_at')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_customer_accounts_data_mode');
            $table->timestamps();

            $table->unique('customer_id', 'uq_customer_accounts_customer');
            $table->unique(['email', 'data_mode'], 'uq_customer_accounts_email_mode');
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->cascadeOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('recipient_name', 100);
            $table->string('phone', 30);
            $table->string('address_line', 255);
            $table->string('city', 100);
            $table->string('province', 100);
            $table->string('postal_code', 10)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_account_id', 'is_default'], 'idx_customer_addresses_account_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_accounts');
    }
};
