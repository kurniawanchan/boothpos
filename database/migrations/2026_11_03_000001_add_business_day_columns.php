<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 042-dashboard-revamp — tanggal bisnis tersimpan + instan penyelesaian.
 *
 * `orders.completed_at` = saat order MENJADI penjualan (checkout langsung, atau tab dilunasi);
 * `business_date` pada orders/payments/cashier_sessions = hari bisnis (zona waktu toko + jam batas
 * hari) yang ditetapkan SERVER saat baris dibuat/diselesaikan, bukan dihitung ulang dari
 * `created_at` tiap laporan dibuka. Alasannya riwayat tidak boleh bergeser bila zona/cut-off
 * diubah kemudian (Konstitusi IV), dan `config('app.timezone')` tetap UTC.
 *
 * Kolom nullable di sini; migrasi 2026_11_03_000003 mengisi baris yang sudah ada.
 * DDL MySQL tidak ikut transaksi: tiap kolom/indeks dicek sendiri supaya aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('orders', 'business_date')) {
                $table->date('business_date')->nullable()->after('completed_at');
            }
        });
        if (! $this->hasIndex('orders', 'idx_orders_business_date_status')) {
            Schema::table('orders', fn (Blueprint $t) => $t->index(['business_date', 'status'], 'idx_orders_business_date_status'));
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'business_date')) {
                $table->date('business_date')->nullable();
            }
        });
        if (! $this->hasIndex('payments', 'idx_payments_verification_method')) {
            Schema::table('payments', fn (Blueprint $t) => $t->index(['verification', 'method'], 'idx_payments_verification_method'));
        }

        Schema::table('cashier_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('cashier_sessions', 'business_date')) {
                $table->date('business_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        if ($this->hasIndex('orders', 'idx_orders_business_date_status')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('idx_orders_business_date_status'));
        }
        foreach (['completed_at', 'business_date'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }

        if ($this->hasIndex('payments', 'idx_payments_verification_method')) {
            Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('idx_payments_verification_method'));
        }
        if (Schema::hasColumn('payments', 'business_date')) {
            Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('business_date'));
        }
        if (Schema::hasColumn('cashier_sessions', 'business_date')) {
            Schema::table('cashier_sessions', fn (Blueprint $t) => $t->dropColumn('business_date'));
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => $i['name'] === $index);
    }
};
