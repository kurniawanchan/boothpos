<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US2 (FR-013..FR-015, FR-025) — pesanan menu beresep yang SELESAI memotong saldo varian bahan lewat StockService
 * (tipe `recipe_sale`, rujukan = baris menu penyebab), bersama bahan baku dalam satu kesatuan, dan resep yang diubah
 * kemudian tidak menyentuh pesanan lama.
 */
class IngredientConsumptionTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld();
    }

    public function test_one_menu_portion_deducts_each_product_ingredient_from_the_shared_balance(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);

        $res = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $this->assertSame(99, $this->vBalance('mie'));
        $this->assertSame(199, $this->vBalance('telur'));
        $lineId = $res->json('items.0.id');
        foreach (['mie', 'telur'] as $key) {
            $m = $this->recipeMoves($key)->sole();
            $this->assertSame('recipe_sale', $m->type);
            $this->assertSame(-1, (int) $m->qty_change);
            $this->assertSame('order_item', $m->reference_type);
            $this->assertSame($lineId, (int) $m->reference_id, 'rujukan = baris MENU penyebab, bukan baris bahan');
            $this->assertSame($this->outletA->id, (int) $m->outlet_id);
        }
        $this->assertSame(0, StockMovement::where('variant_id', $this->nasiGoreng->id)->count(), 'menu beresep tidak punya pergerakan stok sendiri');
    }

    public function test_two_portions_and_a_direct_sale_of_the_same_product_share_one_balance_with_two_sources(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);

        $this->checkout([[$this->nasiGoreng, 2, []], [$this->ing['telur'], 1, []]])->assertCreated();

        $this->assertSame(197, $this->vBalance('telur'), '200 − 2 (resep) − 1 (langsung)');
        $this->assertSame(98, $this->vBalance('mie'));
        $telur = StockMovement::where('variant_id', $this->ing['telur']->id)->whereIn('type', ['sale', 'recipe_sale'])->orderBy('id')->get();
        $this->assertEqualsCanonicalizing(['recipe_sale', 'sale'], $telur->pluck('type')->all(), 'dua sumber berbeda pada satu saldo');
        $this->assertSame(-2, (int) $telur->firstWhere('type', 'recipe_sale')->qty_change);
        $this->assertSame(-1, (int) $telur->firstWhere('type', 'sale')->qty_change);
    }

    public function test_a_quantity_per_portion_above_one_and_a_portions_yield_are_honoured(): void
    {
        // 4 telur untuk 2 porsi = 2 per porsi; 3 porsi → 6.
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 4], yield: 2);

        $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated();

        $this->assertSame(194, $this->vBalance('telur'));
    }

    public function test_product_and_raw_material_lines_deduct_together_and_fail_together(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1, 'm:soto' => 5]);

        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();
        $this->assertSame(198, $this->vBalance('telur'));
        $this->assertSame(40.0, $this->balance('soto'));

        // Telur habis: seluruh pesanan gagal, bahan baku TIDAK terpotong dan tidak ada pesanan setengah jadi.
        app(\App\Services\StockService::class)->applyMovement($this->ing['telur'], $this->outletA->id, 'adjustment', -198);
        $orders = Order::count();
        $status = $this->checkout([[$this->nasiGoreng, 1, []]])->status();

        $this->assertContains($status, [409, 422]);
        $this->assertSame(40.0, $this->balance('soto'), 'bahan baku tidak terpotong');
        $this->assertSame(0, $this->vBalance('telur'));
        $this->assertSame($orders, Order::count());
    }

    public function test_a_modifier_block_with_a_product_line_only_applies_when_its_option_is_ordered(): void
    {
        $keju = $this->makeIngredient('Keju', 50);
        $this->ing['keju'] = $keju;
        $this->ingredientRecipe($this->indomie, ['mie' => 1]);
        $this->ingredientRecipe($this->indomie, ['keju' => 1], modifierOptionId: $this->opt['telur']->id, name: 'Topping');

        $this->checkout([[$this->indomie, 1, $this->opts('soto')]])->assertCreated();
        $this->assertSame(50, $this->vBalance('keju'), 'tanpa pilihan Telur, blok bermodifier tidak berlaku');

        $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]])->assertCreated();
        $this->assertSame(48, $this->vBalance('keju'), 'pilihan dipesan × qty baris');
        $this->assertSame(97, $this->vBalance('mie'));
    }

    public function test_editing_the_recipe_later_changes_only_future_orders(): void
    {
        $recipe = $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        $first = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated()->json('items.0.id');

        $recipe->lines()->update(['quantity' => 3]);
        $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $this->assertSame(196, $this->vBalance('telur'), '1 + 3');
        $this->assertSame(-1, (int) $this->recipeMoves('telur')->firstWhere('reference_id', $first)->qty_change, 'pergerakan lama tidak berubah');
        $this->assertSame(1, OrderItem::where('id', $first)->count());
    }

    public function test_an_inactive_or_deleted_ingredient_variant_is_never_skipped_silently(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
        $this->ing['telur']->update(['is_active' => false]);

        // Bahan baku nonaktif dilewati (perilaku 087); bahan produk TIDAK: tetap dipotong supaya stok tidak menyimpang.
        $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $this->assertSame(199, $this->vBalance('telur'));
    }

    public function test_a_tab_payment_deducts_product_ingredients_once_even_when_retried(): void
    {
        $this->ingredientRecipe($this->nasiGoreng, ['mie' => 1, 'telur' => 1]);
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $tabId = $tab['id'] ?? $tab['data']['id'];
        $this->apiAddLine($tabId, $this->nasiGoreng->id, 2)->assertSuccessful();
        $this->assertSame(200, $this->vBalance('telur'), 'tab terbuka belum memotong (087)');

        $ref = (string) \Illuminate\Support\Str::uuid();
        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 500000]], $ref)->assertSuccessful();
        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 500000]], $ref);

        $this->assertSame(198, $this->vBalance('telur'));
        $this->assertSame(98, $this->vBalance('mie'));
        $this->assertCount(1, $this->recipeMoves('telur', ['recipe_sale']), 'satu pergerakan per (bahan, baris), bukan per porsi');
    }
}
