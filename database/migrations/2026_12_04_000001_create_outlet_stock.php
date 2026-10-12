<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 045-outlets-per-outlet-menu, Stage 5 — stok per outlet.
 *
 *  - `variant_outlet_stock` / `material_outlet_stock`: saldo per (item, outlet). `product_variants.current_stock` dan
 *    `materials.current_stock` tetap ada sebagai TOTAL tersimpan (Σ saldo outlet, tanpa barang dalam perjalanan) dan
 *    ditulis di transaksi yang sama oleh StockService/MaterialStockService.
 *  - `stock_movements.outlet_id` / `material_stock_movements.outlet_id`: setiap pergerakan membawa outletnya.
 *  - Isi awal: saldo VARIAN ke outlet ASAL produknya, saldo BAHAN ke outlet pertama; pergerakan lama distempel sama.
 *    Jumlah semua saldo outlet = total lama (invarian I2) — diperiksa di akhir dan migrasi GAGAL bila tidak.
 *  - Enum tipe pergerakan diperluas (transfer_*, adjustment untuk bahan) untuk Tahap 6.
 *
 * Re-runnable: tiap langkah memeriksa keadaan; `down()` menghapus yang dibuat di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('variant_outlet_stock')) {
            Schema::create('variant_outlet_stock', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->integer('current_stock')->default(0);
                $table->timestamps();
                $table->unique(['variant_id', 'outlet_id'], 'uniq_variant_outlet_stock_pair');
                $table->index('outlet_id', 'idx_variant_outlet_stock_outlet');
            });
            DB::statement('ALTER TABLE variant_outlet_stock ADD CONSTRAINT chk_variant_outlet_stock_nonneg CHECK (current_stock >= 0)');
        }

        if (! Schema::hasTable('material_outlet_stock')) {
            Schema::create('material_outlet_stock', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
                $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->decimal('current_stock', 14, 3)->default(0);
                $table->timestamps();
                $table->unique(['material_id', 'outlet_id'], 'uniq_material_outlet_stock_pair');
                $table->index('outlet_id', 'idx_material_outlet_stock_outlet');
            });
            DB::statement('ALTER TABLE material_outlet_stock ADD CONSTRAINT chk_material_outlet_stock_nonneg CHECK (current_stock >= 0)');
        }

        foreach (['stock_movements', 'material_stock_movements'] as $table) {
            if (! Schema::hasColumn($table, 'outlet_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('outlet_id')->nullable()->after($table === 'stock_movements' ? 'variant_id' : 'material_id'));
            }
        }

        $first = (int) DB::table('outlets')->orderBy('id')->value('id');

        if ($first > 0) {
            // Pergerakan lama: varian → outlet asal produknya (jatuh ke outlet pertama bila produk/outlet hilang).
            DB::statement("UPDATE stock_movements m
                JOIN product_variants v ON v.id = m.variant_id
                LEFT JOIN products p ON p.id = v.product_id
                LEFT JOIN outlets o ON o.id = p.home_outlet_id
                SET m.outlet_id = COALESCE(o.id, {$first}) WHERE m.outlet_id IS NULL");
            DB::statement("UPDATE material_stock_movements SET outlet_id = {$first} WHERE outlet_id IS NULL");

            // Saldo awal. INSERT IGNORE: aman dijalankan ulang.
            DB::statement("INSERT IGNORE INTO variant_outlet_stock (variant_id, outlet_id, current_stock, created_at, updated_at)
                SELECT v.id, COALESCE(o.id, {$first}), v.current_stock, NOW(), NOW()
                FROM product_variants v LEFT JOIN products p ON p.id = v.product_id LEFT JOIN outlets o ON o.id = p.home_outlet_id");
            DB::statement("INSERT IGNORE INTO material_outlet_stock (material_id, outlet_id, current_stock, created_at, updated_at)
                SELECT m.id, {$first}, m.current_stock, NOW(), NOW() FROM materials m");
        }

        // Verifikasi (gagal keras): tidak ada pergerakan tanpa outlet, dan Σ saldo outlet = total tersimpan.
        $nullMovements = DB::table('stock_movements')->whereNull('outlet_id')->count() + DB::table('material_stock_movements')->whereNull('outlet_id')->count();
        $driftVariants = (int) DB::selectOne('SELECT COUNT(*) AS n FROM product_variants v
            LEFT JOIN (SELECT variant_id, SUM(current_stock) s FROM variant_outlet_stock GROUP BY variant_id) b ON b.variant_id = v.id
            WHERE v.current_stock <> COALESCE(b.s, 0)')->n;
        $driftMaterials = (int) DB::selectOne('SELECT COUNT(*) AS n FROM materials m
            LEFT JOIN (SELECT material_id, SUM(current_stock) s FROM material_outlet_stock GROUP BY material_id) b ON b.material_id = m.id
            WHERE m.current_stock <> COALESCE(b.s, 0)')->n;
        if ($nullMovements > 0 || $driftVariants > 0 || $driftMaterials > 0) {
            throw new RuntimeException("Stage 5 gagal diverifikasi: {$nullMovements} pergerakan tanpa outlet, {$driftVariants} varian dan {$driftMaterials} bahan dengan Σ saldo outlet ≠ total.");
        }

        if ($first > 0) {
            foreach (['stock_movements' => 'idx_stock_movements_outlet', 'material_stock_movements' => 'idx_material_stock_movements_outlet'] as $table => $index) {
                DB::statement("ALTER TABLE {$table} MODIFY outlet_id BIGINT UNSIGNED NOT NULL");
                $fk = "fk_{$table}_outlet";
                if (! collect(DB::select("SELECT CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?", [$table, $fk]))->isNotEmpty()) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$fk} FOREIGN KEY (outlet_id) REFERENCES outlets(id) ON DELETE RESTRICT");
                }
                if (! Schema::hasIndex($table, $index)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index(['outlet_id', 'created_at'], $index));
                }
            }
        }

        DB::statement("ALTER TABLE stock_movements MODIFY type ENUM('purchase','sale','preorder_handover','adjustment','return','initial','transfer_out','transfer_in','transfer_cancel') NOT NULL");
        DB::statement("ALTER TABLE material_stock_movements MODIFY type ENUM('purchase','adjustment','transfer_out','transfer_in','transfer_cancel') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE material_stock_movements MODIFY type ENUM('purchase') NOT NULL");
        DB::statement("ALTER TABLE stock_movements MODIFY type ENUM('purchase','sale','preorder_handover','adjustment','return','initial') NOT NULL");

        foreach (['stock_movements', 'material_stock_movements'] as $table) {
            if (Schema::hasColumn($table, 'outlet_id')) {
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->dropForeign("fk_{$table}_outlet"));
                } catch (\Throwable) {
                }
                Schema::table($table, function (Blueprint $t) use ($table) {
                    if (Schema::hasIndex($table, $table === 'stock_movements' ? 'idx_stock_movements_outlet' : 'idx_material_stock_movements_outlet')) {
                        $t->dropIndex($table === 'stock_movements' ? 'idx_stock_movements_outlet' : 'idx_material_stock_movements_outlet');
                    }
                    $t->dropColumn('outlet_id');
                });
            }
        }

        Schema::dropIfExists('material_outlet_stock');
        Schema::dropIfExists('variant_outlet_stock');
    }
};
