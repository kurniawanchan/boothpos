<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 US4 (T031) — GET /reports/materials/usage (contracts §2): per jenis pergerakan per bahan per outlet di periode
 * tanggal bisnis, nilai rupiah, total (hanya rupiah), tren harian, top 10, dan PARITAS dengan buku besar bahan.
 * Periode = 6..1 hari lalu supaya koreksi awal fixture (hari ini) tidak ikut.
 */
class MaterialReportUsageTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
        [$d2, $d3, $d4, $d5] = [$this->daysAgo(2), $this->daysAgo(3), $this->daysAgo(4), $this->daysAgo(5)];
        $this->move('soto', $this->outletA, 'sale', -5, $d2);
        $this->move('soto', $this->outletA, 'sale', -3, $d3);
        $this->move('soto', $this->outletA, 'return', 2, $d3);
        $this->move('soto', $this->outletA, 'waste', -1, $d4);
        $this->move('soto', $this->outletA, 'adjustment', -1, $d4, 'Selisih opname');
        $this->move('soto', $this->outletA, 'adjustment', 4, $d5, 'Koreksi');
        $this->move('soto', $this->outletA, 'purchase', 10, $d5);
        $this->move('soto', $this->outletA, 'transfer_out', -2, $d5);
        $this->move('soto', $this->outletB, 'transfer_in', 2, $d5);
        $this->move('soto', $this->outletA, 'sale', -9, $this->daysAgo(30));   // di luar periode
        [$this->from, $this->to] = [$this->daysAgo(6), $this->daysAgo(1)];
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function usage(?int $outlet = null, array $q = [])
    {
        $req = $outlet ? $this->flushHeaders()->withHeader('X-Outlet-Id', (string) $outlet) : $this->flushHeaders();

        return $req->getJson('/api/v1/reports/materials/usage?'.http_build_query($q + ['date_from' => $this->from, 'date_to' => $this->to]));
    }

    public function test_totals_per_movement_type_with_values(): void
    {
        $res = $this->usage()->assertOk();
        $a = collect($res->json('data'))->first(fn ($r) => $r['name'] === 'Indomie Soto' && $r['outlet_id'] === $this->outletA->id);

        $this->assertSame(['6.000', '1.000', '4.000', '1.000', '10.000', '0.000', '2.000'],
            [$a['sold_net'], $a['wasted'], $a['adjusted_in'], $a['adjusted_out'], $a['purchased'], $a['transferred_in'], $a['transferred_out']]);
        $this->assertSame(['18000.00', '3000.00', '3000.00'], [$a['sold_net_value'], $a['wasted_value'], $a['adjusted_out_value']]);

        $b = collect($res->json('data'))->first(fn ($r) => $r['name'] === 'Indomie Soto' && $r['outlet_id'] === $this->outletB->id);
        $this->assertSame('2.000', $b['transferred_in']);

        $this->assertSame('18000.00', $res->json('totals.sold_net_value'));
        $this->assertSame('3000.00', $res->json('totals.wasted_value'));
        $this->assertSame('30000.00', $res->json('totals.purchased_value'));
    }

    public function test_daily_trend_and_top_lists(): void
    {
        $res = $this->usage($this->outletA->id)->assertOk();
        $trend = collect($res->json('trend'))->keyBy('date');
        $this->assertSame('15000.00', $trend[$this->daysAgo(2)]['sold_net_value']);
        $this->assertSame('3000.00', $trend[$this->daysAgo(3)]['sold_net_value']);
        $this->assertSame('3000.00', $trend[$this->daysAgo(4)]['wasted_value']);
        $this->assertCount(6, $res->json('trend'));   // satu titik per tanggal bisnis periode, termasuk hari tanpa pergerakan

        $this->assertSame([['material_id' => $this->mat['soto']->id, 'name' => 'Indomie Soto', 'value' => '18000.00']], $res->json('top_used'));
        $this->assertSame('Indomie Soto', $res->json('top_wasted.0.name'));
    }

    public function test_figures_match_the_material_ledger_for_the_same_outlet_and_period(): void
    {
        $row = collect($this->usage($this->outletA->id)->json('data'))->firstWhere('name', 'Indomie Soto');
        $ledger = collect($this->flushHeaders()->withHeader('X-Outlet-Id', (string) $this->outletA->id)
            ->getJson("/api/v1/materials/movements?material_id={$this->mat['soto']->id}&from={$this->from}&to={$this->to}&per_page=100")->json('data'));
        $sum = fn (string $type) => $ledger->where('type', $type)->sum(fn ($m) => (float) $m['qty_change']);

        $this->assertEquals(-($sum('sale') + $sum('return')), (float) $row['sold_net']);
        $this->assertEquals(-$sum('waste'), (float) $row['wasted']);
        $this->assertEquals($sum('purchase'), (float) $row['purchased']);
    }

    public function test_period_is_validated_and_needs_the_reports_menu(): void
    {
        $this->usage(null, ['date_from' => $this->to, 'date_to' => $this->from])->assertStatus(422);
        $this->flushHeaders()->getJson('/api/v1/reports/materials/usage?date_from=2024-01-01&date_to=2026-01-01')->assertStatus(422);
        $this->actingAs($this->cashier, 'sanctum');
        $this->usage()->assertForbidden();
    }
}
