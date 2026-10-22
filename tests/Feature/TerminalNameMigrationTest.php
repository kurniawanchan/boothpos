<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 064 / T003 — skema dan dedupe nama terminal. Perubahan skema (DDL) tidak dijalankan ulang di dalam tes
 * (commit implisit MySQL merusak RefreshDatabase); yang diuji: hasil skema lewat information_schema, dan langkah
 * data (dedupe) lewat refleksi karena murni DML.
 */
class TerminalNameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runDedupe(): void
    {
        $migration = require database_path('migrations/2026_12_26_000001_terminal_name_identity.php');
        $m = new \ReflectionMethod($migration, 'dedupeActiveNames');
        $m->setAccessible(true);
        $m->invoke($migration);
    }

    private function device(Outlet $outlet, string $name, string $code, ?string $revokedAt = null): Device
    {
        $d = Device::forceCreate(['outlet_id' => $outlet->id, 'code' => $code, 'name' => $name]);
        if ($revokedAt) {
            $d->forceFill(['revoked_at' => $revokedAt])->save();
        }

        return $d;
    }

    public function test_device_code_accepts_null(): void
    {
        $col = DB::selectOne("SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'code'");
        $this->assertSame('YES', $col->n);

        $outlet = Outlet::first() ?? Outlet::factory()->create();
        $a = Device::forceCreate(['outlet_id' => $outlet->id, 'code' => null, 'name' => 'A']);
        $b = Device::forceCreate(['outlet_id' => $outlet->id, 'code' => null, 'name' => 'B']);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_the_receipt_number_is_unique_per_outlet_not_globally(): void
    {
        $o1 = Outlet::first() ?? Outlet::factory()->create();
        $o2 = Outlet::factory()->create();

        // Order mentah lewat factory terlalu berat; cukup periksa indeks di information_schema.
        $idx = DB::select("SELECT INDEX_NAME AS n, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME");
        $byName = collect($idx)->pluck('cols', 'n');

        $this->assertSame('outlet_id,receipt_no', $byName['uniq_orders_outlet_receipt_no'] ?? null);
        $this->assertArrayNotHasKey('orders_receipt_no_unique', $byName->all());
        $this->assertNotSame($o1->id, $o2->id);
    }

    public function test_the_receipt_counter_table_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('outlet_receipt_counters'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('outlet_receipt_counters', ['outlet_id', 'receipt_seq']));
    }

    public function test_dedupe_suffixes_duplicate_active_names_per_outlet(): void
    {
        $o1 = Outlet::first() ?? Outlet::factory()->create();
        $o2 = Outlet::factory()->create();
        $first = $this->device($o1, 'Kasir', 'T01');
        $second = $this->device($o1, ' kasir ', 'T02');
        $third = $this->device($o1, 'KASIR', 'T03');
        $other = $this->device($o2, 'Kasir', 'T01');
        $blank = $this->device($o1, '   ', 'T04');

        $this->runDedupe();

        $this->assertSame('Kasir', $first->fresh()->name, 'yang tertua tetap');
        $this->assertSame('kasir 2', $second->fresh()->name);
        $this->assertSame('KASIR 3', $third->fresh()->name);
        $this->assertSame('Kasir', $other->fresh()->name, 'outlet lain tidak tersentuh');
        $this->assertSame('Terminal', $blank->fresh()->name);
    }

    public function test_dedupe_does_not_collide_with_an_existing_suffixed_name(): void
    {
        $o = Outlet::first() ?? Outlet::factory()->create();
        $this->device($o, 'Kasir', 'T01');
        $dupe = $this->device($o, 'Kasir', 'T02');
        $this->device($o, 'Kasir 2', 'T03');

        $this->runDedupe();

        $names = Device::withoutGlobalScopes()->where('outlet_id', $o->id)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $this->assertCount(3, $names->unique());
        $this->assertSame('Kasir 3', $dupe->fresh()->name);
    }

    public function test_a_revoked_terminal_keeps_its_name_and_does_not_block_others(): void
    {
        $o = Outlet::first() ?? Outlet::factory()->create();
        $old = $this->device($o, 'Kasir', 'T01', now()->toDateTimeString());
        $new = $this->device($o, 'Kasir', 'T02');

        $this->runDedupe();

        $this->assertSame('Kasir', $old->fresh()->name);
        $this->assertSame('Kasir', $new->fresh()->name);
    }
}
