<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 017-company-onboarding — pipeline sales/ops internal untuk company yang
 * di-onboarding, BUKAN entitas multi-tenant (lihat spec.md's "Scope
 * clarified" note). `data_mode` disertakan langsung di CREATE (tabel
 * baru) — company adalah data bisnis/transaksional (setara Customer/
 * Vendor), lihat CLAUDE.md "Seed data dan DEMO/LIVE mode".
 *
 * activation_code_hash disimpan ter-hash (Hash::make, seperti
 * users.password), bukan plaintext — kode 6 digit dibaca dari email lalu
 * diketik ulang, jadi bukan token bearer seperti payment_proofs.
 * proof_token yang memang dirancang untuk disalin apa adanya
 * (research.md R2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_type_id')->constrained('business_types')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('contact_name', 100);
            // Sengaja TIDAK unique — satu kontak yang sama boleh mewakili
            // lebih dari satu company (lihat spec.md Edge Cases).
            $table->string('contact_email', 150);
            $table->string('contact_phone', 30)->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['pending_activation', 'active'])->default('pending_activation');
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_code_expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_companies_data_mode');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
