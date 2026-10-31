<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialStockMovement;
use App\Models\OrderItem;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\TestCase;

/**
 * 087 US2 (T025) — checkout ritel (`POST /orders`) memotong bahan pilihan modifier dari saldo outlet transaksi.
 */
class ModifierMaterialConsumptionTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    public function test_the_spec_example_deducts_only_mapped_options_multiplied_by_line_qty(): void
    {
        // Indomie Kuah ×2 (SOTO, Sedang, Telur) → Indomie Soto −2, Telur −2; "Sedang" tanpa bahan.
        $r = $this->checkout([[$this->indomie, 2, $this->opts('soto', 'sedang', 'telur')]])->assertCreated();

        $this->assertSame(48.0, $this->balance('soto'));
        $this->assertSame(98.0, $this->balance('telur'));
        $this->assertSame(50.0, $this->balance('ayam'));
        $this->assertSame(100.0, $this->balance('cabai'));

        $lineId = $r->json('items.0.id');
        $moves = $this->consumptionMovements('sale');
        $this->assertCount(2, $moves);
        $this->assertTrue($moves->every(fn ($m) => $m->reference_type === 'order_item' && (int) $m->reference_id === $lineId && (int) $m->outlet_id === $this->outletA->id));
        $this->assertArrayNotHasKey('material_warnings', $r->json());
    }

    public function test_two_options_on_one_line_using_the_same_material_make_one_movement(): void
    {
        $this->checkout([[$this->indomie, 3, $this->opts('soto', 'extra', 'telur', 'double')]])->assertCreated();

        $this->assertSame(91.0, $this->balance('telur')); // (1 + 2) × 3
        $this->assertSame(94.0, $this->balance('cabai')); // 2 × 3
        $this->assertSame(1, MaterialStockMovement::where('type', 'sale')->where('material_id', $this->mat['telur']->id)->count());
    }

    public function test_two_menus_draw_from_one_shared_balance_and_other_outlets_are_untouched(): void
    {
        $this->checkout([
            [$this->indomie, 1, $this->opts('soto', 'telur')],
            [$this->nasiGoreng, 1, $this->opts('telur')],
        ])->assertCreated();

        $this->assertSame(98.0, $this->balance('telur'));
        $this->assertSame(100.0, $this->balance('telur', $this->outletB));
        $this->assertEquals(98 + 100, (float) $this->mat['telur']->fresh()->current_stock);
    }

    public function test_movements_are_written_in_material_then_line_order(): void
    {
        $this->checkout([
            [$this->nasiGoreng, 1, $this->opts('telur')],
            [$this->indomie, 1, $this->opts('ayam', 'extra', 'telur')],
        ])->assertCreated();

        $pairs = $this->consumptionMovements('sale')->map(fn ($m) => [$m->material_id, (int) $m->reference_id])->all();
        $sorted = $pairs;
        usort($sorted, fn ($a, $b) => $a <=> $b);
        $this->assertSame($sorted, $pairs);
    }

    public function test_options_without_mappings_and_inactive_materials_do_not_move_stock(): void
    {
        $this->mat['cabai']->update(['is_active' => false]);

        $this->checkout([[$this->indomie, 1, $this->opts('soto', 'extra')]])->assertCreated();

        $this->assertSame(100.0, $this->balance('cabai'));
        $this->assertSame(49.0, $this->balance('soto'));
    }

    public function test_untracked_product_stock_still_deducts_materials(): void
    {
        $this->indomie->update(['track_stock' => false]);

        $this->checkout([[$this->indomie, 1, $this->opts('soto')]])->assertCreated();

        $this->assertSame(49.0, $this->balance('soto'));
    }

    public function test_fractional_quantities_multiply_without_per_portion_rounding(): void
    {
        $this->opt['sedang']->materials()->create(['material_id' => $this->mat['cabai']->id, 'quantity' => 0.5]);

        $this->checkout([[$this->indomie, 3, $this->opts('soto', 'sedang')]])->assertCreated();

        $this->assertSame(98.5, $this->balance('cabai'));
    }

    public function test_material_cost_is_added_to_the_line_cost_and_order_total_cost_but_not_to_the_bill(): void
    {
        $r = $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]])->assertCreated();

        // Telur Rp2.500 per butir (vendor acuan); Indomie Soto tanpa harga vendor → 0.
        $line = OrderItem::find($r->json('items.0.id'));
        $this->assertEquals(5000 + 2500, (float) $line->cost_price);
        $this->assertEquals(7500 * 2, (float) $line->order->total_cost);
        $this->assertEquals((15000 + 3000) * 2, (float) $line->order->subtotal);
    }

    public function test_changing_a_mapping_after_the_sale_does_not_touch_past_movements(): void
    {
        $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]])->assertCreated();
        $before = $this->consumptionMovements('sale')->map->only(['material_id', 'qty_change'])->all();

        $this->opt['telur']->materials()->update(['quantity' => 5]);

        $this->assertSame($before, $this->consumptionMovements('sale')->map->only(['material_id', 'qty_change'])->all());
        $this->assertSame(99.0, $this->balance('telur'));
    }

    public function test_demo_sales_never_touch_live_material_stock(): void
    {
        ModeGate::runAs('demo', function () {
            $this->assertSame(0, Material::count(), 'bahan LIVE tidak terlihat di DEMO');
        });

        $liveTelur = $this->balance('telur');
        ModeGate::runAs('demo', fn () => $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]]));

        $this->assertSame($liveTelur, $this->balance('telur'));
    }
}
