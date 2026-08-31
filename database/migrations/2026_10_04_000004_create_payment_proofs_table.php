<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PENYIMPANGAN DISENGAJA DARI schema-pos-mvp.sql v1.0
     * =====================================================
     * Skema asli mendefinisikan payment_proofs.payment_id sebagai NOT
     * NULL. Itu KONTRADIKTIF dengan openapi-pos-mvp.yaml dan
     * uml-pos-mvp.md, yang keduanya menggambarkan alur: bukti diunggah
     * LEBIH DULU lewat POST /payment-proofs (mengembalikan proof_token),
     * SEBELUM record payment dibuat. Pada urutan itu, payment_id belum
     * ada saat baris payment_proofs pertama kali ditulis.
     *
     * Resolusi: payment_id dibuat NULLABLE, dan ditambah kolom
     * 'proof_token' (UUID, unik) sebagai kunci pencarian sementara.
     * Saat payment benar-benar dibuat dan proof_token dikonsumsi,
     * payment_id di-set. Baris dengan payment_id NULL lebih dari 24 jam
     * dianggap sampah dan aman dibersihkan (lihat PaymentProofController).
     *
     * schema-pos-mvp.sql SEHARUSNYA diperbarui agar konsisten dengan ini.
     */
    public function up(): void
    {
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->uuid('proof_token')->unique();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 50);
            $table->unsignedInteger('file_size');
            $table->enum('captured_via', ['webcam', 'upload']);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
