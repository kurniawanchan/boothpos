<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\LowStockHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 US3 (T025) — GET /dashboard/materials (contracts §6): hitungan = ringkasan laporan posisi dengan ambang bawaan
 * (7/3) untuk outlet yang sama (FR-015), stok produk menipis dari aturan yang ada, nilai terbuang per periode, rupiah
 * hanya untuk menu `reports`, cache dikunci outlet + periode, dan `computed_at`.
 */
class DashboardMaterialStatsTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
        $this->seedRiskScenario();
        // Terbuang: hari ini 1 Soto di A (Rp 3.000), 5 hari lalu 2 Soto di A (Rp 6.000).
        $this->move('soto', $this->outletA, 'waste', -1);
        $this->move('soto', $this->outletA, 'waste', -2, $this->daysAgo(5));
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function stats(string $period = 'today', ?int $outlet = null)
    {
        $req = $outlet ? $this->flushHeaders()->withHeader('X-Outlet-Id', (string) $outlet) : $this->flushHeaders();

        return $req->getJson("/api/v1/dashboard/materials?period={$period}");
    }

    private function positionSummary(?int $outlet = null): array
    {
        $req = $outlet ? $this->flushHeaders()->withHeader('X-Outlet-Id', (string) $outlet) : $this->flushHeaders();

        return $req->getJson('/api/v1/reports/materials/position')->json('summary');
    }

    public function test_counts_match_the_report_summary_for_the_same_outlet(): void
    {
        foreach ([null, $this->outletA->id, $this->outletB->id] as $outlet) {
            $s = $this->positionSummary($outlet);
            $res = $this->stats('today', $outlet)->assertOk();
            $this->assertSame(
                ['negative' => $s['negative'], 'out' => $s['out'], 'low' => $s['low'], 'at_risk_products' => $s['at_risk_products']],
                $res->json('counts'),
                'outlet '.($outlet ?? 'semua'),
            );
            $this->assertSame($s['stock_value'], $res->json('stock_value'));
        }
        $this->assertNotSame($this->stats('today', $this->outletA->id)->json('counts'), $this->stats('today', $this->outletB->id)->json('counts'));
    }

    public function test_low_stock_products_and_wasted_value_follow_the_period(): void
    {
        $res = $this->stats('today', $this->outletA->id)->assertOk();
        $this->assertSame(LowStockHistory::current(), $res->json('low_stock_products'));
        // Hari ini: 1 × 3.000. Skenario risiko juga membuang 2 Soto 4 hari lalu → 7 hari: (1 + 2 + 2) × 3.000.
        $this->assertSame('3000.00', $res->json('wasted_value'));
        $this->assertSame('15000.00', $this->stats('7d', $this->outletA->id)->json('wasted_value'));
        $res->assertJsonStructure(['params' => ['window_days', 'risk_days'], 'computed_at']);
    }

    public function test_money_is_absent_for_users_without_the_reports_menu(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['dashboard']]);
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'role_id' => $role->id]), 'sanctum');

        $res = $this->stats('today', $this->outletA->id)->assertOk();
        $this->assertArrayHasKey('counts', $res->json());
        $this->assertArrayNotHasKey('stock_value', $res->json());
        $this->assertArrayNotHasKey('wasted_value', $res->json());
    }

    public function test_it_needs_the_dashboard_menu_and_a_valid_period(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role' => 'cashier', 'role_id' => $role->id]), 'sanctum');
        $this->stats()->assertForbidden();

        $this->actingAs($this->owner, 'sanctum');
        $this->stats('year')->assertStatus(422);
    }
}
