<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ModifierMaterialConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 089 US4 — bahan pilihan dipotong `jumlah bahan × jumlah pilihan × qty baris`; void/refund/buang mengikuti
 * potongan nyata. Telur = 1 butir per pilihan, Double Telur = 2 butir (fixtures 087).
 */
class ModifierMaterialQuantityTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->opt['telur']->update(['max_qty' => 5]);
        $this->opt['double']->update(['max_qty' => 5]);
    }

    private function order(int $lineQty, array $selections): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->nasiGoreng->id, 'qty' => $lineQty, 'modifier_selections' => $selections]],
            'payments' => [['method' => 'cash', 'amount' => 900000]],
        ]);
    }

    public function test_material_is_deducted_per_unit_of_the_choice_times_the_line_quantity(): void
    {
        $this->order(3, [['option_id' => $this->opt['telur']->id, 'qty' => 2]])->assertCreated();

        $this->assertSame(94.0, $this->balance('telur'), '1 butir × 2 pilihan × 3 porsi = 6');
        $this->assertCount(1, $this->consumptionMovements('sale'));
    }

    public function test_options_sharing_one_material_add_up_with_their_quantities(): void
    {
        $this->order(1, [['option_id' => $this->opt['telur']->id, 'qty' => 2], ['option_id' => $this->opt['double']->id, 'qty' => 3]])->assertCreated();

        $this->assertSame(92.0, $this->balance('telur'), '(1 × 2) + (2 × 3) = 8');
        $this->assertSame(1, \App\Models\MaterialStockMovement::where('type', 'sale')->where('material_id', $this->mat['telur']->id)->count());
    }

    public function test_the_material_cost_of_the_choice_follows_its_quantity(): void
    {
        $r = $this->order(2, [['option_id' => $this->opt['telur']->id, 'qty' => 3]])->assertCreated();

        // Telur Rp2.500 × 3 pilihan = Rp7.500 per porsi, di atas biaya varian Rp7.000.
        $this->assertEquals(7000 + 7500, (float) OrderItem::find($r->json('items.0.id'))->cost_price);
    }

    public function test_a_refund_returns_exactly_what_was_deducted(): void
    {
        $id = $this->order(2, [['option_id' => $this->opt['double']->id, 'qty' => 2]])->assertCreated()->json('id');
        $this->assertSame(92.0, $this->balance('telur'), '2 butir × 2 × 2');

        $subject = ['order_id' => $id, 'reason_code' => 'wrong_item', 'reason' => 'salah pesan'];
        $this->postJson("/api/v1/orders/{$id}/refund", $subject + ['override_token' => $this->overrideToken('refund', $subject, $this->outletA->id)])->assertOk();

        $this->assertSame(100.0, $this->balance('telur'));
    }

    public function test_a_void_returns_exactly_what_was_deducted(): void
    {
        $id = $this->order(1, [['option_id' => $this->opt['telur']->id, 'qty' => 4]])->assertCreated()->json('id');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->postJson("/api/v1/orders/{$id}/void", ['reason' => 'salah input'])->assertOk();

        $this->assertSame(100.0, $this->balance('telur'));
    }

    public function test_wasting_a_cooked_line_records_the_same_quantity_as_waste(): void
    {
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $lineId = max($this->apiAddLine($tab, $this->nasiGoreng->id, 2, ['modifier_selections' => [['option_id' => $this->opt['telur']->id, 'qty' => 2]]])->assertCreated()->json('lines.*.id'));

        app(ModifierMaterialConsumption::class)->wasteLine(OrderItem::find($lineId), $this->cashier);

        $this->assertSame(96.0, $this->balance('telur'));
        $this->assertCount(1, $this->consumptionMovements('waste'));
    }

    public function test_a_tab_never_moves_material_until_it_completes_and_then_uses_the_final_quantity(): void
    {
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $lineId = max($this->apiAddLine($tab, $this->nasiGoreng->id, 1, ['modifier_selections' => [['option_id' => $this->opt['telur']->id, 'qty' => 1]]])->assertCreated()->json('lines.*.id'));
        $this->patchJson("/api/v1/tabs/{$tab}/items/{$lineId}", ['modifier_selections' => [['option_id' => $this->opt['telur']->id, 'qty' => 3]]])->assertOk();
        $this->assertCount(0, $this->consumptionMovements(), 'mengubah jumlah selama tab terbuka tidak menyentuh bahan');

        $total = (float) \App\Models\Order::find($tab)->total_amount;
        $this->apiPay($tab, [['method' => 'cash', 'amount' => $total]])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame(97.0, $this->balance('telur'));
    }
}
