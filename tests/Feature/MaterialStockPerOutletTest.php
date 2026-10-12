<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 5 (T073) — stok bahan per outlet: penerimaan PO ke outlet PO, koreksi per outlet, daftar per outlet.
 */
class MaterialStockPerOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->material = Material::factory()->create(['current_stock' => 0]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function bal($outlet): float
    {
        return (float) MaterialOutletStock::where(['material_id' => $this->material->id, 'outlet_id' => $outlet->id])->value('current_stock');
    }

    public function test_receiving_a_purchase_order_adds_to_the_outlet_of_the_order(): void
    {
        $po = PurchaseOrder::factory()->create(['created_by' => auth()->id(), 'status' => 'ordered', 'outlet_id' => $this->b->id]);
        $po->items()->create(['line_type' => 'material', 'material_id' => $this->material->id, 'qty' => 8, 'unit_price' => 1000, 'line_total' => 8000]);

        $this->patchJson("/api/v1/purchase-orders/{$po->id}/status", ['status' => 'received'])->assertOk();

        $this->assertSame(8.0, $this->bal($this->b));
        $this->assertSame(0.0, $this->bal($this->a));
        $this->assertEquals(8, (float) $this->material->fresh()->current_stock);
    }

    public function test_adjustments_are_per_outlet_and_never_negative(): void
    {
        $this->postJson("/api/v1/materials/{$this->material->id}/adjustments", ['outlet_id' => $this->a->id, 'qty_change' => 5.5, 'reason' => 'opname'])->assertCreated();
        $this->postJson("/api/v1/materials/{$this->material->id}/adjustments", ['outlet_id' => $this->b->id, 'qty_change' => 2, 'reason' => 'opname'])->assertCreated();

        $this->assertSame(5.5, $this->bal($this->a));
        $this->assertSame(2.0, $this->bal($this->b));
        $this->postJson("/api/v1/materials/{$this->material->id}/adjustments", ['outlet_id' => $this->b->id, 'qty_change' => -3, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame(2.0, $this->bal($this->b));
        $this->assertEquals(7.5, (float) $this->material->fresh()->current_stock);
    }

    public function test_an_adjustment_needs_an_outlet_with_several_and_the_list_shows_balances_per_visible_outlet(): void
    {
        $this->postJson("/api/v1/materials/{$this->material->id}/adjustments", ['qty_change' => 1, 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('outlet_id');
        $this->postJson("/api/v1/materials/{$this->material->id}/adjustments", ['outlet_id' => $this->b->id, 'qty_change' => 4, 'reason' => 'x'])->assertCreated();

        $row = collect($this->getJson('/api/v1/materials')->assertOk()->json('data'))->firstWhere('id', $this->material->id);
        $this->assertSame([$this->b->code => '4.000'], collect($row['outlet_stock'])->pluck('on_hand', 'outlet_code')->all());

        $narrowed = collect($this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/materials')->json('data'))->firstWhere('id', $this->material->id);
        $this->assertSame([], $narrowed['outlet_stock']);
    }
}
