<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 059 — persetujuan seller atas item sebuah penjualan POS. SATU baris per seller per siklus; status
 * keseluruhan penjualan TIDAK disimpan (diturunkan oleh App\Support\OrderApprovalSummary), sehingga
 * penjualan lama tanpa baris tetap berfungsi tanpa migrasi data.
 *
 * Tanpa `data_mode`: baris hanya dijangkau lewat Order ber-scope DEMO/LIVE dan ikut clone lewat
 * `via orders.order_id` (CloneRegistry), seperti order_reference_images.
 *
 * `active_slot` + UNIQUE menjamin SATU persetujuan aktif per (penjualan, seller) di level database
 * (pola seller_settlements); NULL berarti sudah dibatalkan (riwayat), dan MySQL membolehkan banyak NULL.
 *
 * CHECK gambar hanya "stempel tak punya gambar". Kebalikannya (draw/upload WAJIB bergambar) dijaga service,
 * bukan DB: clone LIVE→DEMO menyalin berkas dan bila berkas sumber hilang kolomnya jadi NULL — CHECK ketat
 * akan menggagalkan seluruh clone demi satu gambar yang hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->restrictOnDelete();
            $table->string('artist_name', 150); // snapshot: seller boleh diganti nama / dihapus lunak kemudian
            $table->enum('method', ['draw', 'upload', 'stamp']);
            $table->string('image_path', 255)->nullable(); // disk privat `local`; NULL tepat saat stempel
            $table->string('image_mime', 32)->nullable();
            $table->string('items_hash', 67); // "v1:" + SHA-256 baris seller ini (tanpa id, lihat OrderApprovalSummary)
            $table->json('items_snapshot');   // daftar yang dibaca penyetuju, untuk audit
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('approver_name', 150);
            $table->boolean('on_behalf')->default(false); // owner/admin menyetujui atas nama seller
            $table->timestamp('approved_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidation_reason', 40)->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['order_id', 'artist_id', 'active_slot'], 'uq_order_approvals_active');
            $table->index('artist_id');
        });

        DB::statement("ALTER TABLE order_approvals ADD CONSTRAINT chk_order_approvals_image CHECK (method <> 'stamp' OR image_path IS NULL)");
        DB::statement('ALTER TABLE order_approvals ADD CONSTRAINT chk_order_approvals_slot CHECK ((invalidated_at IS NULL) = (active_slot IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_approvals');
    }
};
