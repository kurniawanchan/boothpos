<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 087 — jenis pergerakan bahan dari penjualan (research D4, D7, D8):
 *  - `sale`   : bahan pilihan modifier dipotong saat pesanan SELESAI;
 *  - `return` : dikembalikan saat void/refund pesanan selesai (sebesar potongan bersih per baris);
 *  - `waste`  : baris yang sudah dimasak lalu dibatalkan "buang" di tab terbuka.
 * Rujukan selalu `order_item` + id baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE material_stock_movements MODIFY type ENUM('purchase','adjustment','transfer_out','transfer_in','transfer_cancel','sale','return','waste') NOT NULL");
    }

    public function down(): void
    {
        // Memotong enum dengan baris hidup akan gagal atau merusak data; tolak dengan jelas.
        $count = DB::table('material_stock_movements')->whereIn('type', ['sale', 'return', 'waste'])->count();
        if ($count > 0) {
            throw new RuntimeException("Tidak bisa rollback 087: masih ada {$count} pergerakan bahan berjenis sale/return/waste.");
        }

        DB::statement("ALTER TABLE material_stock_movements MODIFY type ENUM('purchase','adjustment','transfer_out','transfer_in','transfer_cancel') NOT NULL");
    }
};
