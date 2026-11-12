<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer commerce (2026-11-11) — pesanan pelanggan dari aplikasi Android.
 *
 * Sengaja TIDAK memakai `orders` (penjualan POS: wajib event, shift kasir,
 * dan pengguna staf) maupun `shipments` (anak satu-ke-satu dari `preorders`):
 * pesanan pelanggan adalah PERMINTAAN yang menunggu konfirmasi staf, dengan
 * siklus hidupnya sendiri.
 *
 * `customer_orders`
 *  - `order_number` unik untuk SELURUH tabel (kedua mode): ia parameter rute
 *    publik, dibuat acak oleh App\Support\CustomerOrderNumber.
 *  - `customer_account_id` dan `customer_id` RESTRICT: akun/kontak yang punya
 *    pesanan tidak boleh bisa dihapus keras.
 *  - kontak dan alamat adalah SNAPSHOT saat checkout (JSON untuk alamat):
 *    mengubah profil atau alamat tersimpan tidak mengubah pesanan lama.
 *  - (`customer_account_id`, `idempotency_key`) unik: pengaman terakhir agar
 *    ketukan ganda / percobaan ulang tidak membuat pesanan kedua; per akun,
 *    jadi satu pelanggan tidak bisa menyelidiki kunci milik pelanggan lain.
 *    `idempotency_hash` = SHA-256 muatan, untuk menolak kunci yang sama
 *    dengan isi berbeda.
 *  - `stock_deducted_at`: stok TIDAK bergerak saat pesanan dibuat; terisi
 *    saat staf mengonfirmasi (pengurangan stok lewat StockService), dan
 *    menentukan apakah pembatalan harus mengembalikan stok — tepat sekali.
 *  - `data_mode` langsung di CREATE (dicari langsung lewat nomor / daftar staf).
 *
 * Tiga tabel anak tanpa `data_mode`: hanya dijangkau lewat pesanannya dan
 * ikut terhapus bersamanya (CASCADE).
 *  - `customer_order_items.variant_id` RESTRICT: varian yang pernah dipesan
 *    tidak boleh dihapus keras; nama/SKU/harga adalah snapshot.
 *  - `customer_order_shipments`: paling banyak SATU per pesanan (UNIQUE).
 *  - `customer_order_status_events`: riwayat hanya-tambah yang dilihat
 *    pelanggan; hanya `created_at` (tidak pernah diubah). `actor_user_id`
 *    SET NULL supaya riwayat tetap ada walau pengguna stafnya dihapus;
 *    `metadata` adalah detail audit staf dan tidak pernah dikirim ke pelanggan.
 */
return new class extends Migration
{
    private const STATUSES = [
        'awaiting_confirmation', 'confirmed', 'packing', 'ready_for_pickup',
        'shipped', 'delivered', 'picked_up', 'cancelled',
    ];

    public function up(): void
    {
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 20)->unique('uq_customer_orders_number');
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->enum('fulfilment_method', ['pickup', 'courier']);
            $table->enum('status', self::STATUSES)->default('awaiting_confirmation')->index('idx_customer_orders_status');
            $table->string('contact_name', 100);
            $table->string('contact_phone', 30);
            $table->string('contact_email', 100);
            $table->json('shipping_address')->nullable(); // NULL untuk ambil di tempat
            $table->decimal('subtotal', 14, 2);
            $table->decimal('shipping_cost', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('notes', 500)->nullable();          // catatan pelanggan
            $table->string('cancel_reason', 255)->nullable();
            $table->string('idempotency_key', 64);
            $table->char('idempotency_hash', 64);
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('data_mode', ['demo', 'live'])->default('live')->index('idx_customer_orders_data_mode');
            $table->timestamps();

            $table->unique(['customer_account_id', 'idempotency_key'], 'uq_customer_orders_idempotency');
            $table->index(['customer_account_id', 'created_at'], 'idx_customer_orders_account_created');
        });

        Schema::create('customer_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('sku_snapshot', 20);
            $table->string('name_snapshot', 255); // "nama produk — nama varian"
            $table->decimal('unit_price', 14, 2);
            $table->unsignedInteger('qty');
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('customer_order_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->string('courier_name', 50);
            $table->string('service_name', 50)->nullable();
            $table->string('tracking_number', 50)->nullable();
            $table->decimal('shipping_cost', 14, 2)->default(0); // snapshot nilai pesanan saat dikirim
            $table->timestamp('dispatched_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique('customer_order_id', 'uq_customer_order_shipments_order');
        });

        Schema::create('customer_order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_order_id')->constrained('customer_orders')->cascadeOnDelete();
            $table->enum('status', self::STATUSES);
            $table->enum('event_type', ['status_changed', 'tracking_updated', 'shipping_cost_set'])->default('status_changed');
            $table->string('note', 255)->nullable(); // teks polos yang dilihat pelanggan
            $table->enum('actor_type', ['customer', 'staff', 'system']);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();    // detail audit staf; tidak pernah diserialisasi ke pelanggan
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_order_id', 'occurred_at'], 'idx_customer_order_events_order_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_order_status_events');
        Schema::dropIfExists('customer_order_shipments');
        Schema::dropIfExists('customer_order_items');
        Schema::dropIfExists('customer_orders');
    }
};
