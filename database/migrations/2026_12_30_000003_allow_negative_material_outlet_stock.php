<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 087 — saldo bahan per outlet boleh minus (keputusan pemilik 2026-10-31, FR-014 = izinkan; research D6).
 *
 * Penjualan tidak pernah ditolak karena stok bahan: angka bahan di dapur sering tidak persis, dan menghentikan
 * kasir lebih merugikan daripada saldo minus sementara. Invarian "tidak minus" PINDAH ke
 * MaterialStockService: hanya jenis `sale`/`waste` yang boleh membuat minus; koreksi, transfer, dan lainnya
 * tetap ditolak seperti sebelumnya.
 */
return new class extends Migration
{
    private const CHECK = 'chk_material_outlet_stock_nonneg';

    public function up(): void
    {
        if ($this->checkExists()) {
            DB::statement('ALTER TABLE material_outlet_stock DROP CHECK '.self::CHECK);
        }
    }

    public function down(): void
    {
        $negative = DB::table('material_outlet_stock')->where('current_stock', '<', 0)->count();
        if ($negative > 0) {
            throw new RuntimeException("Tidak bisa rollback 087: {$negative} saldo bahan per outlet masih minus.");
        }

        if (! $this->checkExists()) {
            DB::statement('ALTER TABLE material_outlet_stock ADD CONSTRAINT '.self::CHECK.' CHECK (current_stock >= 0)');
        }
    }

    private function checkExists(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'material_outlet_stock')
            ->where('CONSTRAINT_NAME', self::CHECK)
            ->exists();
    }
};
