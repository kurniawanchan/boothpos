<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 (T043, SC-005) — laporan posisi untuk 300 bahan × 3 outlet (30 hari pergerakan penjualan) < 3 detik, dan jumlah
 * kueri TIDAK tumbuh menurut banyaknya bahan (Konstitusi V: tanpa N+1). Data massal dimasukkan langsung (data uji),
 * bukan lewat MaterialStockService, supaya penyiapan tetap cepat.
 */
class MaterialReportPerformanceTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    private ?int $outletC = null;

    private function seedBulk(int $materials, string $prefix): void
    {
        $mode = ModeGate::current();
        $this->outletC ??= Outlet::factory()->create(['code' => 'WJC'])->id;
        $outlets = [$this->outletA->id, $this->outletB->id, $this->outletC];
        $now = now();
        $first = (int) DB::table('materials')->max('id') + 1;
        DB::table('materials')->insert(array_map(fn ($i) => [
            'code' => "{$prefix}{$i}", 'name' => "Bahan Uji {$prefix}{$i}", 'unit' => 'pcs', 'kind' => 'ingredient', 'current_stock' => 0,
            'is_active' => 1, 'data_mode' => $mode, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, $materials)));
        $ids = DB::table('materials')->where('id', '>=', $first)->pluck('id');

        $balances = [];
        $moves = [];
        foreach ($ids as $id) {
            foreach ($outlets as $o) {
                $balances[] = ['material_id' => $id, 'outlet_id' => $o, 'current_stock' => 40, 'created_at' => $now, 'updated_at' => $now];
                for ($d = 0; $d < 30; $d++) {
                    $moves[] = ['material_id' => $id, 'outlet_id' => $o, 'type' => 'sale', 'qty_change' => -1, 'stock_before' => 0, 'stock_after' => 0,
                        'reference_type' => 'order_item', 'data_mode' => $mode, 'created_at' => $this->midday($this->daysAgo($d))];
                }
            }
        }
        foreach (array_chunk($balances, 1000) as $c) {
            DB::table('material_outlet_stock')->insert($c);
        }
        foreach (array_chunk($moves, 2000) as $c) {
            DB::table('material_stock_movements')->insert($c);
        }
    }

    private function measure(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $this->flushHeaders()->getJson('/api/v1/reports/materials/position')->assertOk();
        $elapsed = microtime(true) - $t;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$elapsed, $queries];
    }

    public function test_300_materials_by_3_outlets_in_under_3_seconds_with_a_flat_query_count(): void
    {
        $this->buildMaterialReportWorld();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->sellDays($this->outletA, [$this->daysAgo(1), $this->daysAgo(2)]);
        $this->seedBulk(30, 'S');
        [, $queriesSmall] = $this->measure();

        $this->seedBulk(270, 'L');
        [$elapsed, $queriesLarge] = $this->measure();

        $this->assertLessThan(3.0, $elapsed, "posisi 300 × 3 butuh {$elapsed} dtk");
        $this->assertSame($queriesSmall, $queriesLarge, 'jumlah kueri bertambah mengikuti jumlah bahan (N+1)');
    }
}
