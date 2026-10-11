<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class LiveFingerprintTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_capture_is_stable_when_nothing_changes(): void
    {
        $this->buildGraph('live');

        $this->assertSame(LiveFingerprint::capture(), LiveFingerprint::capture());
    }

    public function test_changing_a_live_row_is_detected(): void
    {
        $g = $this->buildGraph('live');
        $before = LiveFingerprint::capture();

        DB::table('customers')->where('id', $g['customer'])->update(['name' => 'Changed']);

        $this->assertSame(['customers'], LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_changing_demo_rows_is_not_detected(): void
    {
        $this->buildGraph('live');
        $demo = $this->buildGraph('demo');
        $before = LiveFingerprint::capture();

        DB::table('customers')->where('id', $demo['customer'])->update(['name' => 'Changed']);
        DB::table('artists')->where('id', $demo['artist'])->update(['name' => 'Renamed']);

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_a_table_without_data_mode_is_covered_through_its_purchase_order(): void
    {
        $g = $this->buildGraph('live');
        $before = LiveFingerprint::capture();

        DB::table('purchase_order_items')->where('id', $g['poItem'])->update(['qty' => '9.00']);

        $this->assertContains('purchase_order_items', LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_shared_tables_and_cascade_targets_are_covered(): void
    {
        $g = $this->buildGraph('live');
        $before = LiveFingerprint::capture();

        DB::table('users')->where('id', $g['user'])->update(['updated_at' => now()->addDay()]);

        $this->assertContains('users', LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertArrayHasKey('preorder_notifications', $before);
        $this->assertArrayHasKey('pos_drafts', $before);
    }

    public function test_registry_is_in_fk_order_and_scopes_are_mode_safe(): void
    {
        $tables = array_keys(CloneRegistry::tables());

        foreach (CloneRegistry::tables() as $table => $spec) {
            foreach (CloneRegistry::references($table) as $col => $parent) {
                $this->assertLessThanOrEqual(
                    array_search($table, $tables, true),
                    array_search($parent, $tables, true),
                    "{$table}.{$col} -> {$parent} harus induk lebih dulu (atau self-FK)",
                );
            }
        }

        $this->assertSame("data_mode = 'live'", CloneRegistry::scope('orders', 'live'));
        $this->assertStringContainsString("purchase_orders WHERE data_mode = 'demo'", CloneRegistry::scope('purchase_order_items', 'demo'));
        $this->expectException(\InvalidArgumentException::class);
        CloneRegistry::scope('orders', "live' OR 1=1 --");
    }
}
