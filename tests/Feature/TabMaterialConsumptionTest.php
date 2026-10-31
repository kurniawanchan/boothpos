<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 087 US2 (T026) — tab: bahan dipotong hanya saat pesanan SELESAI (FR-015 = B), dari isi akhir tab.
 */
class TabMaterialConsumptionTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
    }

    private function add(int $qty, array $opts, ?int $tab = null): int
    {
        $ids = $this->apiAddLine($tab ?? $this->tab, $this->indomie->id, $qty, ['modifier_option_ids' => $opts])->assertCreated()->json('lines.*.id');

        return max($ids);
    }

    private function totalDue(int $tab): float
    {
        return (float) Order::find($tab)->total_amount;
    }

    public function test_editing_an_open_tab_never_moves_material_stock(): void
    {
        $line = $this->add(2, $this->opts('soto', 'telur'));
        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 1])->assertOk();
        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['modifier_option_ids' => $this->opts('ayam', 'double')])->assertOk();
        $gone = $this->add(1, $this->opts('soto'));
        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$gone}")->assertOk();
        $this->postJson("/api/v1/tabs/{$this->tab}/move", ['table_id' => DiningTable::factory()->create()->id])->assertOk();

        $this->assertCount(0, $this->consumptionMovements());
        $this->assertSame(100.0, $this->balance('telur'));
    }

    public function test_merging_two_open_tabs_moves_no_material(): void
    {
        $this->add(1, $this->opts('soto'));
        $other = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $this->add(1, $this->opts('ayam'), $other);

        $this->postJson("/api/v1/tabs/{$this->tab}/merge", ['source_tab_id' => $other])->assertOk();

        $this->assertCount(0, $this->consumptionMovements());
    }

    public function test_a_partial_payment_moves_nothing_and_the_final_payment_deducts_the_final_contents_once(): void
    {
        $line = $this->add(2, $this->opts('soto', 'telur'));
        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['modifier_option_ids' => $this->opts('ayam', 'telur')])->assertOk();

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 1000]])->assertOk()->assertJsonPath('status', 'open');
        $this->assertCount(0, $this->consumptionMovements());

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => $this->totalDue($this->tab)]])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame(50.0, $this->balance('soto'));
        $this->assertSame(48.0, $this->balance('ayam'));
        $this->assertSame(98.0, $this->balance('telur'));
        $this->assertCount(2, $this->consumptionMovements('sale'));
    }

    public function test_a_split_sale_deducts_its_own_lines_and_the_rest_deducts_when_paid(): void
    {
        $whole = $this->add(1, $this->opts('soto', 'telur'));
        $partial = $this->add(3, $this->opts('ayam', 'extra'));

        $res = $this->postJson("/api/v1/tabs/{$this->tab}/split", [
            'client_ref' => $this->newLocalRef(),
            'items' => [['item_id' => $whole, 'qty' => 1], ['item_id' => $partial, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 200000]],
        ])->assertCreated();

        // Penjualan split: SOTO+Telur ×1 dan AYAM+Extra ×1.
        $this->assertSame(49.0, $this->balance('soto'));
        $this->assertSame(99.0, $this->balance('telur'));
        $this->assertSame(49.0, $this->balance('ayam'));
        $this->assertSame(98.0, $this->balance('cabai'));

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => $this->totalDue($this->tab)]])->assertOk()->assertJsonPath('status', 'completed');

        // Sisa tab: AYAM+Extra ×2. Total = isi awal tab, tanpa ganda.
        $this->assertSame(47.0, $this->balance('ayam'));
        $this->assertSame(94.0, $this->balance('cabai'));
        $this->assertSame(49.0, $this->balance('soto'));
        $this->assertSame(99.0, $this->balance('telur'));

        // Biaya bahan (Telur Rp2.500) hanya sekali, pada baris penjualan split.
        $paidId = $res->json('paid_order.id');
        $this->assertEquals(5000 + 2500, (float) OrderItem::where('order_id', $paidId)->where('id', $whole)->value('cost_price'));
        $this->assertEquals(5000, (float) OrderItem::where('order_id', $this->tab)->value('cost_price'));
    }
}
