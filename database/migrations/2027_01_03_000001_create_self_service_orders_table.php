<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 090-self-service-qr-ordering — kiriman pesan mandiri pelanggan, DITAHAN di sini sampai staf memverifikasi
 * pembayaran lalu menekan "Kirim ke dapur". Baris `orders`, stok, pembayaran, dan tiket dapur baru dibuat
 * saat itu (research D1): `orders.session_id` NOT NULL dan stok terpotong saat baris tab dibuat, jadi
 * pesanan yang belum terverifikasi tidak boleh hidup di `orders`.
 *
 * `lines`, total, dan pajak adalah POTRET saat kirim (Konstitusi IV) — dipakai struk pelanggan dan sebagai
 * pembanding saat dispatch. `outlet_id` dan `table_id` datang dari token QR yang divalidasi server, tidak
 * pernah dari body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_service_orders', function (Blueprint $table) {
            $table->id();
            $table->char('receipt_token', 40)->unique();
            $table->char('local_ref', 36)->unique();
            $table->string('reference_no', 20);
            $table->date('business_date');
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->foreignId('table_id')->constrained('dining_tables');
            $table->string('table_name_snapshot', 100);
            $table->enum('service_type', ['dine_in', 'takeaway']);
            $table->enum('status', ['awaiting_verification', 'verified', 'dispatched', 'rejected'])->default('awaiting_verification');

            $table->json('lines');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('tax_rate_snapshot', 5, 2)->default(0);
            $table->decimal('total_amount', 14, 2);

            // Data pribadi (UU PDP): hanya di entitas ini, tidak masuk log aktivitas maupun respons publik lain.
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 150)->nullable();

            $table->foreignId('payment_channel_id')->constrained('payment_channels');
            // Satu bukti hanya boleh dipakai SATU kiriman (sekali pakai); dijaga juga oleh service.
            $table->foreignId('proof_id')->unique('uniq_self_service_proof')->constrained('payment_proofs');

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('verify_reference', 100)->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->string('reject_reason', 255)->nullable();

            // Tanpa FK ke orders di sini: orders baru mendapat kolom tautan balik di migrasi berikutnya.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dispatched_at')->nullable();

            $table->timestamps();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_self_service_orders_data_mode');

            $table->index(['outlet_id', 'status', 'id'], 'idx_self_service_outlet_status');
            $table->unique(['outlet_id', 'business_date', 'reference_no'], 'uniq_self_service_reference');
            $table->index(['table_id', 'status'], 'idx_self_service_table_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_service_orders');
    }
};
