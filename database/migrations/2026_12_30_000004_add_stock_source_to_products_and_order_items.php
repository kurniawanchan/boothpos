<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 087 US6 — sumber stok per produk (research D16, D18).
 *
 * `products.stock_source`: `variant` = stok di varian (perilaku lama, bawaan untuk SEMUA produk yang sudah ada);
 * `modifier` = varian tidak punya stok, yang berkurang hanya bahan pilihan modifier. Bahan modifier dipotong untuk
 * semua produk (keputusan pemilik FR-025 = gabungan); kolom ini hanya menentukan apakah stok VARIAN bergerak.
 *
 * `order_items.stock_source`: snapshot per baris saat stok dikurangi, supaya pengembalian (kurangi, hapus, void,
 * refund) mengikuti yang dulu benar-benar terjadi walau pengaturan produk diubah di tengah jalan. NULL = baris lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('stock_source', ['variant', 'modifier'])->default('variant');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->enum('stock_source', ['variant', 'modifier'])->nullable();
        });
    }

    public function down(): void
    {
        $modifier = DB::table('products')->where('stock_source', 'modifier')->count();
        if ($modifier > 0) {
            throw new RuntimeException("Tidak bisa rollback 087 US6: {$modifier} produk masih bersumber stok Modifier.");
        }

        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('stock_source'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('stock_source'));
    }
};
