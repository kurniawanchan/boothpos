<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 045-outlets-per-outlet-menu, Stage 2 (S2b) — outlet wajib.
 *
 * Dijalankan SETELAH 2026_12_02_000001 (kolom nullable + backfill) dan SETELAH penstempelan
 * BelongsToOutlet terpasang di setiap model, sehingga tidak ada baris baru yang masuk tanpa outlet.
 *
 *  - `outlet_id` menjadi NOT NULL pada tujuh tabel operasional;
 *  - FK dibuat RESTRICT (bukan SET NULL): outlet tidak bisa terhapus selama masih dirujuk, sejalan
 *    dengan guard hapus outlet (OutletDeleteGuard);
 *  - nama meja (`dining_tables`) dan stasiun (`stations`) unik PER OUTLET, bukan per mode data
 *    (D7). Kunci lama (data_mode, area, name) / (data_mode, name) digantikan.
 *
 * Migrasi ini berhenti dengan galat bila masih ada baris tanpa outlet — sengaja, supaya tidak
 * pernah ada data operasional tanpa outlet yang diam-diam lolos.
 */
return new class extends Migration
{
    private const TABLES = [
        'cashier_sessions', 'orders', 'payments', 'kitchen_tickets',
        'dining_tables', 'stations', 'preorders',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $remaining = DB::table($table)->whereNull('outlet_id')->count();
            if ($remaining > 0) {
                throw new RuntimeException("Tabel {$table} masih punya {$remaining} baris tanpa outlet; jalankan backfill S2a dahulu.");
            }

            // FK nullOnDelete harus dilepas dulu sebelum kolom menjadi NOT NULL.
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['outlet_id']);
            });
            DB::statement("ALTER TABLE `{$table}` MODIFY `outlet_id` BIGINT UNSIGNED NOT NULL");
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('outlet_id')->references('id')->on('outlets')->restrictOnDelete();
            });
        }

        if ($this->hasIndex('dining_tables', 'uniq_dining_tables_mode_area_name')) {
            Schema::table('dining_tables', fn (Blueprint $blueprint) => $blueprint->dropUnique('uniq_dining_tables_mode_area_name'));
        }
        Schema::table('dining_tables', function (Blueprint $blueprint) {
            $blueprint->unique(['outlet_id', 'name'], 'uniq_dining_tables_outlet_name');
        });

        if ($this->hasIndex('stations', 'uniq_stations_mode_name')) {
            Schema::table('stations', fn (Blueprint $blueprint) => $blueprint->dropUnique('uniq_stations_mode_name'));
        }
        Schema::table('stations', function (Blueprint $blueprint) {
            $blueprint->unique(['outlet_id', 'name'], 'uniq_stations_outlet_name');
        });
    }

    public function down(): void
    {
        Schema::table('stations', fn (Blueprint $blueprint) => $blueprint->dropUnique('uniq_stations_outlet_name'));
        Schema::table('stations', fn (Blueprint $blueprint) => $blueprint->unique(['data_mode', 'name'], 'uniq_stations_mode_name'));
        Schema::table('dining_tables', fn (Blueprint $blueprint) => $blueprint->dropUnique('uniq_dining_tables_outlet_name'));
        Schema::table('dining_tables', fn (Blueprint $blueprint) => $blueprint->unique(['data_mode', 'area', 'name'], 'uniq_dining_tables_mode_area_name'));

        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign(['outlet_id']));
            DB::statement("ALTER TABLE `{$table}` MODIFY `outlet_id` BIGINT UNSIGNED NULL");
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }
};
