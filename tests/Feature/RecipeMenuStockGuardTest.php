<?php

namespace Tests\Feature;

use App\Models\MaterialStockMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
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
 * 092 US3 (FR-011..FR-014; research D4, D5) — pesanan menu beresep yang melebihi bahan ditolak 409
 * `insufficient_recipe_stock`; tab terbuka MENAHAN bahan; penolakan atomik; hanya kebutuhan RESEP yang ditolak.
 * Nasi Goreng memakai 1 telur per porsi, Indomie Kuah 2 telur per porsi (+ pilihan rasa wajib).
 */
class RecipeMenuStockGuardTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    private function recipe(ProductVariant $variant, array $lines): Recipe
    {
        $recipe = Recipe::create([
            'product_id' => $variant->product_id, 'name' => 'Dasar', 'yield' => 1,
            'effective_from' => now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $l) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $l[0], 'shrinkage_pct' => $l[1] ?? 0, 'display_order' => ++$i]);
        }

        return $recipe;
    }

    /** Nasi Goreng = 1 telur/porsi; telur outlet A = 10. */
    private function eggWorld(): void
    {
        $this->setStock('telur', $this->outletA, 10);
        $this->recipe($this->nasiGoreng, ['telur' => [1]]);
    }

    private function newTab(): int
    {
        $tab = $this->apiOpenTab($this->session->id)->assertCreated()->json();

        return $tab['id'] ?? $tab['data']['id'];
    }

    private function cardStock(): int
    {
        $products = $this->getJson('/api/v1/products?pos=1&with_variants=1&per_page=100')->assertOk()->json('data');

        return collect($products)->flatMap(fn ($p) => $p['variants'])->firstWhere('id', $this->nasiGoreng->id)['outlet_stock'];
    }

    private function assertRecipeStockRefusal($response, string $materialKey = 'telur'): void
    {
        $response->assertStatus(409)->assertJsonPath('code', 'insufficient_recipe_stock')
            ->assertJsonPath('limiting.material_id', $this->mat[$materialKey]->id);
    }

    public function test_checkout_over_the_stock_is_refused_and_leaves_nothing_behind(): void
    {
        $this->eggWorld();

        $this->assertRecipeStockRefusal($this->checkout([[$this->nasiGoreng, 11, []]]));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10.0, $this->balance('telur'));
        $this->assertCount(0, $this->consumptionMovements());
    }

    public function test_checkout_up_to_the_stock_is_accepted_and_empties_the_ingredient(): void
    {
        $this->eggWorld();

        $this->checkout([[$this->nasiGoreng, 10, []]])->assertCreated();

        $this->assertSame(0.0, $this->balance('telur'));
        $this->assertSame(0, $this->cardStock());
    }

    public function test_the_refusal_names_the_ingredient_and_the_portions_left(): void
    {
        $this->eggWorld();

        $r = $this->checkout([[$this->nasiGoreng, 11, []]]);

        $r->assertStatus(409)->assertJsonPath('limiting.name', 'Telur')->assertJsonPath('limiting.unit', 'butir')
            ->assertJsonPath('limiting.on_hand', '10.000')->assertJsonPath('limiting.required', '11.000')
            ->assertJsonPath('limiting.available_portions', 10);
        $this->assertStringContainsString('Telur', $r->json('message'));
    }

    public function test_an_open_tab_holds_ingredients_against_another_tab(): void
    {
        $this->eggWorld();
        $a = $this->newTab();
        $b = $this->newTab();

        $this->apiAddLine($a, $this->nasiGoreng->id, 6)->assertSuccessful();
        $this->assertSame(4, $this->cardStock(), 'kartu menurun begitu baris masuk tab, setara stok varian');

        $this->assertRecipeStockRefusal($this->apiAddLine($b, $this->nasiGoreng->id, 6));
        $this->assertSame(0, OrderItem::query()->where('order_id', $b)->count(), 'baris yang ditolak tidak tersimpan');
        $this->apiAddLine($b, $this->nasiGoreng->id, 4)->assertSuccessful();
        $this->assertSame(0, $this->cardStock());
        $this->assertSame(10.0, $this->balance('telur'), 'bahan baru dipotong saat selesai, bukan saat baris masuk (087)');
    }

    public function test_removing_a_line_releases_the_hold(): void
    {
        $this->eggWorld();
        $a = $this->newTab();
        $b = $this->newTab();
        $this->apiAddLine($a, $this->nasiGoreng->id, 6)->assertSuccessful();
        $lineA = OrderItem::query()->where('order_id', $a)->value('id');

        $this->assertRecipeStockRefusal($this->apiAddLine($b, $this->nasiGoreng->id, 6));
        $this->deleteJson("/api/v1/tabs/{$a}/items/{$lineA}")->assertSuccessful();

        $this->apiAddLine($b, $this->nasiGoreng->id, 6)->assertSuccessful();
    }

    public function test_a_completed_order_is_not_held_twice(): void
    {
        $this->eggWorld();
        $a = $this->newTab();
        $this->apiAddLine($a, $this->nasiGoreng->id, 6)->assertSuccessful();
        $this->apiPay($a, [['method' => 'cash', 'amount' => 500000]])->assertSuccessful();

        $this->assertSame(4.0, $this->balance('telur'));
        $this->assertSame(4, $this->cardStock(), 'tahanan berubah menjadi potongan nyata, bukan dihitung dua kali');
        $this->apiAddLine($this->newTab(), $this->nasiGoreng->id, 4)->assertSuccessful();
    }

    public function test_raising_a_line_is_checked_but_lowering_never_is(): void
    {
        $this->eggWorld();
        $a = $this->newTab();
        $this->apiAddLine($a, $this->nasiGoreng->id, 3)->assertSuccessful();
        $line = OrderItem::query()->where('order_id', $a)->value('id');

        $this->assertRecipeStockRefusal($this->patchJson("/api/v1/tabs/{$a}/items/{$line}", ['qty' => 11]));
        $this->patchJson("/api/v1/tabs/{$a}/items/{$line}", ['qty' => 10])->assertSuccessful();

        $this->setStock('telur', $this->outletA, 0);   // bahan habis di tengah tab (koreksi/rusak)
        $this->patchJson("/api/v1/tabs/{$a}/items/{$line}", ['qty' => 2])->assertSuccessful();
    }

    public function test_ingredients_are_summed_across_the_lines_of_one_order(): void
    {
        $this->eggWorld();
        $this->recipe($this->indomie, ['telur' => [2]]);

        // 6 × 1 + 3 × 2 = 12 > 10, padahal masing-masing baris sendiri muat (6 dan 6).
        $this->assertRecipeStockRefusal($this->checkout([[$this->nasiGoreng, 6, []], [$this->indomie, 3, $this->opts('soto')]]));
        $this->assertSame(0, Order::query()->count());

        $this->checkout([[$this->nasiGoreng, 4, []], [$this->indomie, 3, $this->opts('soto')]])->assertCreated();   // 4 + 6 = 10
        $this->assertSame(0.0, $this->balance('telur'));
    }

    public function test_a_refused_checkout_is_atomic_across_all_lines(): void
    {
        $this->eggWorld();
        $before = StockMovement::query()->count();

        // Baris pertama tanpa resep (memotong stok varian) lolos, baris kedua beresep gagal: tidak boleh ada sisa.
        $this->assertRecipeStockRefusal($this->checkout([[$this->indomie, 1, $this->opts('soto')], [$this->nasiGoreng, 11, []]]));

        $this->assertSame($before, StockMovement::query()->count(), 'stok varian baris pertama ikut digulung');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_settle_rechecks_when_the_balance_dropped_after_the_line_was_added(): void
    {
        $this->eggWorld();
        $a = $this->newTab();
        $this->apiAddLine($a, $this->nasiGoreng->id, 5)->assertSuccessful();

        $this->setStock('telur', $this->outletA, 3);   // koreksi stok di antara tambah dan bayar

        $this->assertRecipeStockRefusal($this->apiPay($a, [['method' => 'cash', 'amount' => 500000]]));
        $this->assertSame('open', Order::find($a)->status);
        $this->assertSame(0, Payment::query()->where('order_id', $a)->count(), 'tidak ada pembayaran tersimpan');
        $this->assertSame(3.0, $this->balance('telur'));
    }

    public function test_a_retried_checkout_returns_without_a_second_check(): void
    {
        $this->eggWorld();
        $ref = (string) Str::uuid();
        $this->checkout([[$this->nasiGoreng, 10, []]], $ref)->assertCreated();

        $retry = $this->checkout([[$this->nasiGoreng, 10, []]], $ref);

        $this->assertNotSame(409, $retry->status(), 'retry `local_ref` yang sama mengembalikan hasil lama, bukan ditolak karena bahan sudah habis');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_modifier_option_ingredients_still_only_warn(): void
    {
        $this->setStock('telur', $this->outletA, 0);

        // Indomie tanpa resep; pilihan Telur memakai bahan telur yang habis: 087 tetap menjual dengan peringatan.
        $r = $this->checkout([[$this->indomie, 1, $this->opts('soto', 'telur')]])->assertCreated();

        $this->assertNotEmpty($r->json('material_warnings'));
    }

    public function test_a_menu_without_a_recipe_is_not_affected(): void
    {
        $this->setStock('telur', $this->outletA, 0);

        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();   // tanpa resep dan tanpa pilihan: lolos seperti dulu
        $this->assertCount(0, MaterialStockMovement::query()->whereIn('type', ['sale'])->get());
    }
}
