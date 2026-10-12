<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 046-hide-events-preorders, Fase 2 — event tidak lagi wajib.
 *
 * Event dan Pre-order dihapus dari produk, tetapi barisnya TETAP di database sebagai riwayat
 * diam (tidak dikonversi, tidak dihapus, tidak dibalik). Shift, order, dan snapshot rekap kini
 * dibuat tanpa event, jadi `event_id` pada tiga tabel ini harus boleh NULL. FK dipertahankan:
 * baris lama tetap menunjuk event aslinya, hanya baris baru yang NULL.
 *
 * `down()` berhenti bila ada baris ber-event NULL — mengembalikan NOT NULL akan mengarang event.
 */
return new class extends Migration
{
    private const TABLES = ['cashier_sessions', 'orders', 'outlet_settlements'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            // Idempoten: lewati bila sudah nullable (migrasi dijalankan ulang pada salinan).
            if ($this->isNullable($table)) {
                continue;
            }

            $this->dropEventForeignKeys($table);
            DB::statement("ALTER TABLE `{$table}` MODIFY `event_id` BIGINT UNSIGNED NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('event_id')->references('id')->on('events')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $nulls = DB::table($table)->whereNull('event_id')->count();
            if ($nulls > 0) {
                throw new RuntimeException("Tabel {$table} punya {$nulls} baris tanpa event; tidak bisa mengembalikan NOT NULL tanpa mengarang event.");
            }

            $this->dropEventForeignKeys($table);
            DB::statement("ALTER TABLE `{$table}` MODIFY `event_id` BIGINT UNSIGNED NOT NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign('event_id')->references('id')->on('events')->restrictOnDelete());
        }
    }

    /**
     * Nama FK dibaca dari information_schema, bukan ditebak: `artist_settlements` diganti nama
     * menjadi `outlet_settlements` pada 045 tetapi nama kendala lamanya ikut terbawa.
     */
    private function dropEventForeignKeys(string $table): void
    {
        $names = DB::select(
            'SELECT CONSTRAINT_NAME AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            [$table, 'event_id', 'events']
        );

        foreach ($names as $row) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$row->n}`");
        }
    }

    private function isNullable(string $table): bool
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'event_id']
        );

        return $row && $row->n === 'YES';
    }
};
