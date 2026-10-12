<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 046 (T006) — event_id tidak lagi wajib pada shift, order, dan snapshot rekap; FK tetap ada
 * supaya baris lama menunjuk event aslinya.
 */
class EventIdNullableMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_event_columns_accept_null(): void
    {
        foreach (['cashier_sessions', 'orders', 'outlet_settlements'] as $table) {
            $col = DB::selectOne(
                'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'event_id']
            );
            $this->assertSame('YES', $col->n, "{$table}.event_id masih NOT NULL");
        }
    }

    public function test_the_foreign_keys_to_events_are_kept(): void
    {
        foreach (['cashier_sessions', 'orders', 'outlet_settlements'] as $table) {
            $fk = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
                [$table, 'event_id', 'events']
            );
            $this->assertGreaterThan(0, (int) $fk->c, "FK {$table}.event_id hilang");
        }
    }

    public function test_the_migration_is_rerunnable(): void
    {
        $migration = require database_path('migrations/2026_12_10_000001_make_event_id_nullable.php');
        $migration->up(); // sudah nullable → harus lolos tanpa galat

        $this->assertTrue(true);
    }
}
