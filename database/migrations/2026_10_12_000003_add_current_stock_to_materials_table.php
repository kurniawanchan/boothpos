<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 006-purchase-order-and-ops (US1) — materials sebelumnya TIDAK punya
 * konsep stok sama sekali (hanya harga acuan BOM). Kolom ini + tabel
 * material_stock_movements (migrasi berikutnya) adalah jalur sanksi baru
 * yang paralel dengan stock_movements/StockService milik ProductVariant,
 * BUKAN perluasan jalur yang sama — lihat research.md R4 untuk alasan
 * keduanya sengaja dipisah (entitas berbeda, bukan concern yang sama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('current_stock', 12, 3)->default(0)->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('current_stock');
        });
    }
};
