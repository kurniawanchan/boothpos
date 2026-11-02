<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MaterialReportFixtures;
use Tests\TestCase;

/**
 * 088 US4 (T032) — GET /reports/materials/{material}/usage-by-menu (contracts §3): menu yang memakai bahan di
 * periode, dari baris pesanan rujukan pergerakan; pilihan = snapshot baris ∩ pilihan yang memetakan bahan itu.
 */
class MaterialUsageByMenuTest extends TestCase
{
    use MaterialReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMaterialReportWorld();
        // Telur: Indomie Kuah + Telur (1 butir), Nasi Goreng ×2 + Double Telur (2 × 2 butir).
        $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')], [$this->nasiGoreng, 2, $this->opts('double')]])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_menus_and_options_that_used_the_material(): void
    {
        $rows = $this->getJson("/api/v1/reports/materials/{$this->mat['telur']->id}/usage-by-menu")->assertOk()->json('data');

        $this->assertSame([
            ['product_id' => $this->nasiGoreng->product_id, 'product' => 'Nasi Goreng', 'options' => ['Double Telur'], 'qty' => '4.000'],
            ['product_id' => $this->indomie->product_id, 'product' => 'Indomie Kuah', 'options' => ['Telur'], 'qty' => '1.000'],
        ], $rows);
    }

    public function test_another_outlet_sees_nothing_and_access_needs_reports(): void
    {
        $this->withHeader('X-Outlet-Id', (string) $this->outletB->id)
            ->getJson("/api/v1/reports/materials/{$this->mat['telur']->id}/usage-by-menu")->assertOk()->assertJsonPath('data', []);

        $this->actingAs($this->cashier, 'sanctum');
        $this->flushHeaders()->getJson("/api/v1/reports/materials/{$this->mat['telur']->id}/usage-by-menu")->assertForbidden();
    }
}
