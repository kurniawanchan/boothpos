<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\MaterialStockMovement;
use App\Models\User;
use App\Services\MaterialStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 087 (T011) — CHECK ≥ 0 bahan sudah dilepas; penjaga "tidak minus" kini hanya di MaterialStockService.
 * Hanya 'sale'/'waste' boleh membuat saldo minus; koreksi dan transfer tetap ditolak (FR-014b).
 */
class MaterialStockNegativeGuardTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $outlet;

    private Material $material;

    private MaterialStockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = $this->makeOutlets(1)->first();
        $this->material = Material::factory()->create(['current_stock' => 0]);
        $this->stock = app(MaterialStockService::class);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->stock->applyMovement($this->material, $this->outlet->id, 'adjustment', 1);
    }

    private function balance(): float
    {
        return (float) MaterialOutletStock::where(['material_id' => $this->material->id, 'outlet_id' => $this->outlet->id])->value('current_stock');
    }

    public function test_sale_and_waste_may_drive_the_balance_negative_without_clamping(): void
    {
        $sale = $this->stock->applyMovement($this->material, $this->outlet->id, 'sale', -3, 'order_item', 1);
        $this->assertSame(-2.0, $this->balance());
        $this->assertEquals(1, (float) $sale->stock_before);
        $this->assertEquals(-2, (float) $sale->stock_after);

        $this->stock->applyMovement($this->material, $this->outlet->id, 'waste', -0.5, 'order_item', 2);
        $this->assertSame(-2.5, $this->balance());
        $this->assertEquals(-2.5, (float) $this->material->fresh()->current_stock);
    }

    public function test_adjustment_and_transfer_out_are_still_refused_when_they_would_go_negative(): void
    {
        foreach (['adjustment', 'transfer_out'] as $type) {
            try {
                $this->stock->applyMovement($this->material, $this->outlet->id, $type, -2);
                $this->fail("{$type} seharusnya ditolak");
            } catch (ValidationException) {
                $this->assertSame(1.0, $this->balance());
            }
        }

        $this->postJson("/api/v1/materials/{$this->material->id}/adjustments", ['outlet_id' => $this->outlet->id, 'qty_change' => -5, 'reason' => 'x'])->assertStatus(422);
        $this->assertSame(1, MaterialStockMovement::where('material_id', $this->material->id)->count());
    }

    public function test_a_purchase_after_a_negative_balance_adds_from_the_negative_figure(): void
    {
        $this->stock->applyMovement($this->material, $this->outlet->id, 'sale', -2, 'order_item', 1);
        $this->assertSame(-1.0, $this->balance());

        $this->stock->applyMovement($this->material, $this->outlet->id, 'purchase', 10);
        $this->assertSame(9.0, $this->balance());
        $this->assertEquals(9, (float) $this->material->fresh()->current_stock);
    }

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI (2026-10-31): penambahan yang hasilnya MASIH minus (terima PO 1 ke saldo −3)
     * dulu ditolak "stok tidak cukup", padahal FR-014a mewajibkan pembelian/koreksi/transfer masuk menambah dari
     * angka minus. Penambahan selalu boleh; hanya pengurangan yang dijaga.
     */
    public function test_any_increase_into_a_negative_balance_is_allowed_even_if_it_stays_negative(): void
    {
        $this->stock->applyMovement($this->material, $this->outlet->id, 'sale', -4, 'order_item', 1);
        $this->assertSame(-3.0, $this->balance());

        foreach (['adjustment', 'purchase', 'transfer_in'] as $type) {
            $move = $this->stock->applyMovement($this->material, $this->outlet->id, $type, 1);
            $this->assertEquals((float) $move->stock_before + 1, (float) $move->stock_after);
        }
        $this->assertSame(0.0, $this->balance());

        $this->stock->applyMovement($this->material, $this->outlet->id, 'sale', -2, 'order_item', 2);
        try {
            $this->stock->applyMovement($this->material, $this->outlet->id, 'adjustment', -1);
            $this->fail('pengurangan yang membuat saldo minus seharusnya ditolak');
        } catch (ValidationException) {
            $this->assertSame(-2.0, $this->balance());
        }
    }
}
