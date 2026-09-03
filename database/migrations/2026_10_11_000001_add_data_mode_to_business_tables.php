<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 003-seed-demo-live — menambahkan kolom `data_mode` ke SEMUA tabel data
 * bisnis/transaksional supaya data contoh (DEMO) dan data toko sungguhan
 * (LIVE) bisa disaring tanpa saling menimpa (lihat data-model.md).
 *
 * SENGAJA TIDAK menyentuh users/roles/settings/activity_logs/
 * payment_channels — data administratif tidak dibedakan mode (FR-012).
 *
 * DEFAULT 'live' (bukan 'demo') supaya instalasi lama yang di-migrate naik
 * ke fitur ini tetap menganggap seluruh riwayat yang sudah ada sebagai
 * data nyata, tanpa perlu skrip perbaikan data terpisah.
 */
return new class extends Migration
{
    private const TABLES = [
        'events',
        'artists',
        'categories',
        'products',
        'product_variants',
        'customers',
        'vendors',
        'materials',
        'vendor_material_prices',
        'product_variant_bom_lines',
        'cashier_sessions',
        'orders',
        'order_items',
        'preorders',
        'preorder_items',
        'shipments',
        'payments',
        'payment_proofs',
        'stock_movements',
        'artist_settlements',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->enum('data_mode', ['demo', 'live'])
                    ->default('live')
                    ->index("idx_{$table}_data_mode");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('data_mode');
            });
        }
    }
};
