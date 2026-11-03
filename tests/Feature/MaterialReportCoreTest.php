<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MaterialReport;
use App\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 (T005) — inti MaterialReport (research D1–D3, D5): saldo per outlet, laju = pemakaian bersih ÷ HARI JUAL,
 * hari tersisa, status, biaya satuan sama dengan resep, dan aturan baris.
 *
 * Dunia: outlet A punya 4 hari jual dalam 7 hari terakhir (hari lain tutup). Indomie Soto di A: jual 18, kembali 4,
 * buang 2 → pemakaian bersih 16 → laju 4/hari; saldo akhir 6 → 1,5 hari → "akan habis".
 */
class MaterialReportCoreTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();

        $this->seedRiskScenario();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->rows = app(MaterialReport::class)->positions(['window_days' => 7, 'risk_days' => 3, 'target_days' => 7])['rows'];
    }

    private function row(string $name, $outlet): array
    {
        $hit = collect($this->rows)->first(fn ($r) => $r['name'] === $name && $r['outlet_id'] === $outlet->id);
        $this->assertNotNull($hit, "baris {$name} @ {$outlet->code} tidak ada");

        return $hit;
    }

    public function test_usage_rate_is_net_usage_over_selling_days_and_closed_days_do_not_count(): void
    {
        $soto = $this->row('Indomie Soto', $this->outletA);
        $this->assertSame('6.000', $soto['on_hand']);
        $this->assertSame('4.000', $soto['daily_usage']);   // (18 − 4 + 2) ÷ 4 hari jual
        $this->assertEquals(1.5, $soto['days_left']);
        $this->assertSame('low', $soto['status']);
    }

    public function test_statuses_negative_out_unused_and_untracked(): void
    {
        $ayam = $this->row('Indomie Ayam Bawang', $this->outletB);
        $this->assertSame('negative', $ayam['status']);
        $this->assertEquals(0, $ayam['days_left']);

        $this->assertSame('out', $this->row('Telur', $this->outletB)['status']);

        $cabai = $this->row('Cabai Bubuk', $this->outletA);
        $this->assertSame('unused', $cabai['status']);
        $this->assertNull($cabai['days_left']);
        $this->assertFalse($cabai['usage_untracked']);

        $sambal = $this->row('Sambal', $this->outletA);
        $this->assertSame('unused', $sambal['status']);
        $this->assertTrue($sambal['usage_untracked']);
    }

    /** 089 — resep AKTIF memotong stok, jadi bahannya tercatat; resep arsip/belum berlaku/tak tertaut tidak. */
    public function test_a_material_in_an_active_linked_recipe_is_no_longer_untracked(): void
    {
        $recipe = \App\Models\Recipe::query()->sole();
        $untracked = fn () => collect(app(MaterialReport::class)->positions(['window_days' => 7, 'risk_days' => 3, 'target_days' => 7])['rows'])
            ->first(fn ($r) => $r['name'] === 'Sambal' && $r['outlet_id'] === $this->outletA->id)['usage_untracked'];

        $this->assertTrue($untracked(), 'resep tak tertaut ke varian');

        $recipe->variants()->sync([$this->nasiGoreng->id]);
        $this->assertFalse($untracked(), 'resep aktif tertaut memotong stok, jadi tercatat');

        $recipe->update(['archived_at' => now()]);
        $this->assertTrue($untracked(), 'arsip tidak memotong');

        $recipe->update(['archived_at' => null, 'effective_from' => now()->addDays(5)->toDateString()]);
        $this->assertTrue($untracked(), 'belum berlaku tidak memotong');
    }

    public function test_unit_cost_matches_the_recipe_cost_rule_and_values_follow(): void
    {
        $soto = $this->row('Indomie Soto', $this->outletA);
        $this->assertSame('3000.00', $soto['unit_cost']);
        $this->assertSame('18000.00', $soto['stock_value']);
        foreach (['soto', 'ayam', 'telur', 'cabai'] as $k) {
            $expected = app(RecipeService::class)->unitCost($this->mat[$k]->fresh());
            $row = collect($this->rows)->first(fn ($r) => $r['material_id'] === $this->mat[$k]->id);
            $this->assertSame($expected === null ? null : number_format($expected, 2, '.', ''), $row['unit_cost'], $k);
        }
        $this->assertSame('50.00', $this->row('Sambal', $this->outletA)['unit_cost']);   // biaya standar setengah jadi
        $this->assertNull($this->row('Indomie Ayam Bawang', $this->outletB)['stock_value']);   // tanpa harga
    }

    public function test_rows_exist_only_where_a_material_has_a_balance_or_movement_and_risk_sorts_first(): void
    {
        $this->assertNull(collect($this->rows)->first(fn ($r) => $r['name'] === 'Sambal' && $r['outlet_id'] === $this->outletB->id));
        $statuses = array_column($this->rows, 'status');
        $rank = ['negative' => 0, 'out' => 1, 'low' => 2, 'ok' => 3, 'unused' => 4];
        $ranked = array_map(fn ($s) => $rank[$s], $statuses);
        $sorted = $ranked;
        sort($sorted);
        $this->assertSame($sorted, $ranked);
    }

    public function test_inactive_materials_are_left_out(): void
    {
        $this->mat['cabai']->update(['is_active' => false]);
        $rows = app(MaterialReport::class)->positions(['window_days' => 7, 'risk_days' => 3, 'target_days' => 7])['rows'];
        $this->assertNotContains('Cabai Bubuk', array_column($rows, 'name'));
    }
}
