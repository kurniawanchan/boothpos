<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VariantOutletStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 092 US2 (FR-004, FR-006, FR-008; research D6) — varian yang punya resep AKTIF tidak lagi menggerakkan stok
 * variannya sendiri di jalur mana pun; stok menu berasal dari bahan. Menu tanpa resep tidak berubah sama sekali.
 */
class RecipeMenuStockVariantDecouplingTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function recipe(ProductVariant $variant, array $lines = ['telur' => [1]], array $over = []): Recipe
    {
        $recipe = Recipe::create(array_merge([
            'product_id' => $variant->product_id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ], $over));
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $l) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $l[0], 'shrinkage_pct' => $l[1] ?? 0, 'display_order' => ++$i]);
        }

        return $recipe;
    }

    private function variantMovements(ProductVariant $v)
    {
        return StockMovement::query()->where('variant_id', $v->id)->where('type', '!=', 'initial')->orderBy('id')->get();
    }

    private function variantBalance(ProductVariant $v): int
    {
        return (int) VariantOutletStock::query()->where('variant_id', $v->id)->where('outlet_id', $this->outletA->id)->value('current_stock');
    }

    /** Satu tab + satu baris menu (tanpa modifier) lewat API. */
    private function tabWith(ProductVariant $v, int $qty): array
    {
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();
        $tabId = $tab['id'] ?? $tab['data']['id'];
        $this->apiAddLine($tabId, $v->id, $qty)->assertSuccessful();

        return [$tabId, OrderItem::query()->where('order_id', $tabId)->latest('id')->first()];
    }

    public function test_a_variant_with_an_active_recipe_no_longer_tracks_its_own_stock(): void
    {
        $this->assertTrue($this->nasiGoreng->fresh()->tracksOwnStock(), 'tanpa resep: perilaku lama');

        $this->recipe($this->nasiGoreng);

        $this->assertFalse($this->nasiGoreng->fresh()->tracksOwnStock());
        $this->assertTrue($this->indomie->fresh()->tracksOwnStock(), 'menu lain tanpa resep tak berubah');
    }

    public function test_checkout_of_a_recipe_menu_leaves_variant_stock_alone_and_snapshots_the_source(): void
    {
        $this->recipe($this->nasiGoreng);
        $before = $this->variantBalance($this->nasiGoreng);
        $this->assertGreaterThan(0, $before, 'baseline nyata (bukan baris saldo kosong), supaya "tidak berubah" bermakna');

        $r = $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated();

        $this->assertCount(0, $this->variantMovements($this->nasiGoreng), 'FR-008: tidak ada pergerakan stok varian');
        $this->assertSame($before, $this->variantBalance($this->nasiGoreng));
        $this->assertSame('modifier', OrderItem::find($r->json('items.0.id'))->stock_source);
        $this->assertSame(97.0, $this->balance('telur'), 'bahan terpotong sebagai gantinya');
    }

    public function test_a_tab_never_moves_variant_stock_for_a_recipe_menu(): void
    {
        $this->recipe($this->nasiGoreng);
        $before = $this->variantBalance($this->nasiGoreng);
        $this->assertGreaterThan(0, $before, 'baseline nyata (bukan baris saldo kosong), supaya "tidak berubah" bermakna');

        [$tabId, $line] = $this->tabWith($this->nasiGoreng, 2);
        $this->assertSame('modifier', $line->stock_source);
        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 500000]])->assertSuccessful();

        $this->assertCount(0, $this->variantMovements($this->nasiGoreng));
        $this->assertSame($before, $this->variantBalance($this->nasiGoreng));
        $this->assertSame(98.0, $this->balance('telur'));
    }

    public function test_a_line_added_before_the_recipe_existed_still_returns_to_the_variant(): void
    {
        $before = $this->variantBalance($this->nasiGoreng);
        [$tabId, $line] = $this->tabWith($this->nasiGoreng, 2);
        $this->assertSame('variant', $line->stock_source);
        $this->assertSame($before - 2, $this->variantBalance($this->nasiGoreng));

        $this->recipe($this->nasiGoreng);   // resep baru muncul di tengah tab

        $this->deleteJson("/api/v1/tabs/{$tabId}/items/{$line->id}")->assertSuccessful();

        $this->assertSame($before, $this->variantBalance($this->nasiGoreng), 'snapshot baris (variant) menentukan pengembalian, bukan resep saat ini');
    }

    public function test_archiving_the_recipe_returns_the_variant_to_its_own_stock(): void
    {
        $recipe = $this->recipe($this->nasiGoreng);
        $this->assertFalse($this->nasiGoreng->fresh()->tracksOwnStock());

        $recipe->update(['archived_at' => now()]);

        $this->assertTrue($this->nasiGoreng->fresh()->tracksOwnStock());
        $before = $this->variantBalance($this->nasiGoreng);
        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();
        $this->assertSame($before - 2, $this->variantBalance($this->nasiGoreng));
    }

    public function test_a_future_dated_recipe_does_not_decouple_yet(): void
    {
        $this->recipe($this->nasiGoreng, over: ['effective_from' => now()->addDays(3)->toDateString()]);

        $this->assertTrue($this->nasiGoreng->fresh()->tracksOwnStock());
    }

    public function test_a_menu_without_a_recipe_still_moves_variant_stock(): void
    {
        $before = $this->variantBalance($this->indomie);

        $this->checkout([[$this->indomie, 2, $this->opts('soto')]])->assertCreated();

        $this->assertSame($before - 2, $this->variantBalance($this->indomie));
        $this->assertSame('variant', OrderItem::query()->where('variant_id', $this->indomie->id)->value('stock_source'));
    }

    public function test_adjusting_variant_stock_of_a_recipe_menu_is_refused(): void
    {
        $this->recipe($this->nasiGoreng);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->postJson('/api/v1/stock/adjustments', [
            'items' => [['variant_id' => $this->nasiGoreng->id, 'qty_change' => 5]], 'reason' => 'uji',
        ], ['X-Outlet-Id' => (string) $this->outletA->id])->assertStatus(409)->assertJsonPath('code', 'stock_source_modifier');
    }

    public function test_the_order_count_is_unchanged_by_recipe_decoupling(): void
    {
        $this->recipe($this->nasiGoreng);
        $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $this->assertSame(1, Order::query()->count());
    }
}
