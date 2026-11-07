<?php

namespace Tests\Feature;

use App\Models\MaterialStockMovement;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 US2 (FR-007, FR-009, SC-002, SC-004) — contoh spec: 2 porsi "Indomie Kuah" memotong tepat Indomie 2, Telur 2,
 * Sayur 100 gr, Kuah 400 ml; satu pergerakan per bahan per baris; tanpa pemotongan ganda saat percobaan ulang.
 * Dunia uji memakai bahan 'soto' (Indomie), 'telur', 'cabai' (Sayur), 'ayam' (Kuah) dan Nasi Goreng sebagai menunya.
 */
class RecipeMenuStockDeductionTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    /** Resep contoh spec pada $variant: Indomie 1, Telur 1, Sayur 50, Kuah 200; saldo 20 / 10 / 500 / 2000. */
    private function indomieKuahRecipe(ProductVariant $variant): Recipe
    {
        foreach (['soto' => 20, 'telur' => 10, 'cabai' => 500, 'ayam' => 2000] as $key => $stock) {
            $this->setStock($key, $this->outletA, $stock);
        }
        $recipe = Recipe::create([
            'product_id' => $variant->product_id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach (['soto' => 1, 'telur' => 1, 'cabai' => 50, 'ayam' => 200] as $key => $qty) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $qty, 'shrinkage_pct' => 0, 'display_order' => ++$i]);
        }

        return $recipe;
    }

    public function test_two_portions_deduct_each_ingredient_per_the_spec_example(): void
    {
        $this->indomieKuahRecipe($this->nasiGoreng);

        $r = $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();

        $this->assertSame(18.0, $this->balance('soto'), 'Indomie 2 pcs');
        $this->assertSame(8.0, $this->balance('telur'), 'Telur 2 pcs');
        $this->assertSame(400.0, $this->balance('cabai'), 'Sayur 100 gr');
        $this->assertSame(1600.0, $this->balance('ayam'), 'Kuah 400 ml');

        $moves = $this->consumptionMovements('sale');
        $this->assertCount(4, $moves, 'satu pergerakan per bahan');
        $lineId = $r->json('items.0.id');
        $this->assertTrue($moves->every(fn ($m) => $m->reference_type === 'order_item' && (int) $m->reference_id === $lineId));
        $this->assertSame(0, StockMovement::where('variant_id', $this->nasiGoreng->id)->where('type', 'sale')->count(), 'stok varian tidak bergerak');
    }

    public function test_two_lines_sharing_an_ingredient_each_get_their_own_movement(): void
    {
        $this->indomieKuahRecipe($this->nasiGoreng);
        // Indomie Kuah (menu lain) juga memakai Telur: 2 per porsi.
        $recipe = Recipe::create(['product_id' => $this->indomie->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id]);
        $recipe->variants()->sync([$this->indomie->id]);
        $block = $recipe->blocks()->create(['variant_id' => $this->indomie->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat['telur']->id, 'quantity' => 2, 'shrinkage_pct' => 0, 'display_order' => 1]);

        $this->checkout([[$this->nasiGoreng, 2, []], [$this->indomie, 1, $this->opts('soto')]])->assertCreated();

        $this->assertSame(6.0, $this->balance('telur'), '(1 × 2) + (2 × 1) = 4 telur');
        $this->assertSame(2, MaterialStockMovement::where('type', 'sale')->where('material_id', $this->mat['telur']->id)->count());
    }

    public function test_retrying_the_same_checkout_deducts_once(): void
    {
        $this->indomieKuahRecipe($this->nasiGoreng);
        $ref = (string) Str::uuid();

        $this->checkout([[$this->nasiGoreng, 2, []]], $ref)->assertCreated();
        foreach (range(1, 4) as $_) {
            $this->checkout([[$this->nasiGoreng, 2, []]], $ref);
        }

        $this->assertSame(8.0, $this->balance('telur'), 'SC-004: lima kiriman, satu pemotongan');
        $this->assertCount(4, $this->consumptionMovements('sale'));
    }

    public function test_retrying_a_tab_payment_deducts_once(): void
    {
        $this->indomieKuahRecipe($this->nasiGoreng);
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $tabId = $tab['id'] ?? $tab['data']['id'];
        $this->apiAddLine($tabId, $this->nasiGoreng->id, 2)->assertSuccessful();
        $ref = (string) Str::uuid();

        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 500000]], $ref)->assertSuccessful();
        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 500000]], $ref);

        $this->assertSame(8.0, $this->balance('telur'));
        $this->assertCount(4, $this->consumptionMovements('sale'));
    }

    public function test_an_open_tab_does_not_move_ingredients_until_it_completes(): void
    {
        $this->indomieKuahRecipe($this->nasiGoreng);
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $tabId = $tab['id'] ?? $tab['data']['id'];

        $this->apiAddLine($tabId, $this->nasiGoreng->id, 2)->assertSuccessful();

        $this->assertSame(10.0, $this->balance('telur'), 'keputusan 087 dipertahankan: bahan dipotong saat selesai');
        $this->assertCount(0, $this->consumptionMovements('sale'));
    }

    public function test_the_recipe_cost_is_not_added_twice_to_the_line_cost(): void
    {
        $this->indomieKuahRecipe($this->nasiGoreng);

        $r = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $this->assertEqualsWithDelta((float) $this->nasiGoreng->cost_price, (float) OrderItem::find($r->json('items.0.id'))->cost_price, 0.001, 'biaya resep sudah ada di cost_price varian (089 D9)');
    }
}
