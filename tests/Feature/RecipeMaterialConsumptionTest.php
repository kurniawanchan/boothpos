<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Services\ModifierMaterialConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 089 US1 — varian ber-resep memotong stok bahan saat pesanan SELESAI: semua resep aktif dijumlahkan, rumus sama
 * dengan HPP (`jumlah ÷ (1 − susut) ÷ Portions × qty`), void/refund memulihkan dari buku besar.
 * Nasi Goreng (grup Topping opsional) dipakai agar baris tanpa modifier sah.
 */
class RecipeMaterialConsumptionTest extends TestCase
{
    use ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
    }

    /**
     * @param  array<string, array{0: float, 1?: float}>  $lines  kunci bahan => [jumlah, susut%]
     */
    private function recipe(ProductVariant $variant, array $lines, float $yield = 1, string $name = 'Dasar', ?string $from = null, array $over = []): Recipe
    {
        $recipe = Recipe::create(array_merge([
            'product_id' => $variant->product_id, 'name' => $name, 'yield' => $yield,
            'effective_from' => $from ?? now()->subDay()->toDateString(), 'created_by' => $this->cashier->id,
        ], $over));
        $recipe->variants()->sync([$variant->id]);
        $block = $recipe->blocks()->create(['variant_id' => $variant->id, 'modifier_option_id' => null, 'display_order' => 1]);
        $i = 0;
        foreach ($lines as $key => $l) {
            $block->lines()->create(['recipe_id' => $recipe->id, 'material_id' => $this->mat[$key]->id, 'quantity' => $l[0], 'shrinkage_pct' => $l[1] ?? 0, 'display_order' => ++$i]);
        }

        return $recipe;
    }

    private function refund(int $orderId)
    {
        $subject = ['order_id' => $orderId, 'reason_code' => 'wrong_item', 'reason' => 'salah pesan'];

        return $this->postJson("/api/v1/orders/{$orderId}/refund", $subject + ['override_token' => $this->overrideToken('refund', $subject, $this->outletA->id)]);
    }

    public function test_completing_a_sale_deducts_the_recipe_per_portion_times_quantity(): void
    {
        $this->recipe($this->nasiGoreng, ['ayam' => [1], 'cabai' => [2]]);

        $r = $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated();

        $this->assertSame(47.0, $this->balance('ayam'));
        $this->assertSame(94.0, $this->balance('cabai'));
        $this->assertSame(100.0, $this->balance('telur'), 'bahan di luar resep tidak tersentuh');
        $moves = $this->consumptionMovements('sale');
        $this->assertCount(2, $moves);
        $this->assertTrue($moves->every(fn ($m) => $m->reference_type === 'order_item' && (int) $m->reference_id === $r->json('items.0.id') && (int) $m->outlet_id === $this->outletA->id));
    }

    public function test_portions_and_shrinkage_follow_the_cost_formula(): void
    {
        // 2 gr ÷ (1 − 20%) ÷ 4 porsi = 0,625 gr per porsi; 3 porsi = 1,875 gr.
        $this->recipe($this->nasiGoreng, ['cabai' => [2, 20]], yield: 4);

        $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated();

        $this->assertSame(98.125, $this->balance('cabai'));
    }

    public function test_all_active_recipes_of_a_variant_are_summed(): void
    {
        $this->recipe($this->nasiGoreng, ['cabai' => [2]], name: 'Dasar');
        $this->recipe($this->nasiGoreng, ['cabai' => [1], 'telur' => [1]], name: 'Topping standar');

        $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated();

        $this->assertSame(91.0, $this->balance('cabai'), '(2 + 1) × 3');
        $this->assertSame(97.0, $this->balance('telur'));
        $this->assertSame(1, \App\Models\MaterialStockMovement::where('type', 'sale')->where('material_id', $this->mat['cabai']->id)->count(), 'satu pergerakan per (bahan, baris)');
    }

    public function test_recipe_and_modifier_materials_on_one_line_merge_into_one_movement(): void
    {
        $this->recipe($this->nasiGoreng, ['telur' => [1]]);

        $this->checkout([[$this->nasiGoreng, 2, $this->opts('telur')]])->assertCreated();

        $this->assertSame(96.0, $this->balance('telur'), '(1 resep + 1 pilihan) × 2');
        $this->assertSame(1, \App\Models\MaterialStockMovement::where('type', 'sale')->where('material_id', $this->mat['telur']->id)->count());
    }

    public function test_future_archived_and_missing_recipes_do_not_deduct(): void
    {
        $this->recipe($this->nasiGoreng, ['cabai' => [5]], name: 'Masa depan', from: now()->addDays(3)->toDateString());
        $this->recipe($this->nasiGoreng, ['cabai' => [7]], name: 'Arsip', over: ['archived_at' => now()]);

        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();
        $this->checkout([[$this->indomie, 1, $this->opts('soto')]])->assertCreated();   // tanpa resep sama sekali

        $this->assertSame(100.0, $this->balance('cabai'));
        $this->assertCount(1, $this->consumptionMovements('sale'), 'hanya bahan pilihan SOTO milik Indomie');
    }

    public function test_the_recipe_cost_is_counted_once_in_the_line_cost(): void
    {
        $this->recipe($this->nasiGoreng, ['telur' => [4]]);   // 4 × Rp2.500 = Rp10.000 per porsi

        $r = $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();

        // `cost_price` varian disinkronkan dari resep (Rp10.000) lalu disalin ke baris; potongan stok TIDAK menambahnya
        // lagi (research D9). Bila dobel, angkanya 20.000.
        $this->assertEquals(10000, (float) OrderItem::find($r->json('items.0.id'))->cost_price);
        $this->assertEquals(20000, (float) Order::find($r->json('id'))->total_cost);
    }

    public function test_inactive_materials_in_a_recipe_are_skipped(): void
    {
        $this->recipe($this->nasiGoreng, ['cabai' => [2], 'telur' => [1]]);
        $this->mat['cabai']->update(['is_active' => false]);

        $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $this->assertSame(100.0, $this->balance('cabai'));
        $this->assertSame(99.0, $this->balance('telur'));
    }

    /**
     * 092 (keputusan pemilik 2026-11-06) MEMBALIK 089/087 untuk kebutuhan RESEP: dulu penjualan tetap selesai dengan saldo
     * minus + peringatan; kini ditolak 409 dan saldo tidak berubah. Perilaku lama tetap berlaku untuk bahan PILIHAN
     * MODIFIER (test berikutnya).
     */
    public function test_a_recipe_shortfall_is_refused_and_leaves_the_balance_untouched(): void
    {
        $this->recipe($this->nasiGoreng, ['ayam' => [2]]);
        $this->setStock('ayam', $this->outletA, 1);

        $this->checkout([[$this->nasiGoreng, 1, []]])->assertStatus(409)->assertJsonPath('code', 'insufficient_recipe_stock');

        $this->assertSame(1.0, $this->balance('ayam'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_modifier_option_shortfall_still_completes_the_sale_with_a_warning_naming_the_material_and_outlet(): void
    {
        $this->setStock('telur', $this->outletA, 1);

        // Indomie tanpa resep; pilihan "Double Telur" butuh 2 butir: kekurangan bahan PILIHAN tetap hanya peringatan (087).
        $r = $this->checkout([[$this->indomie, 1, $this->opts('soto', 'double')]])->assertCreated();

        $this->assertSame(-1.0, $this->balance('telur'));
        $this->assertSame('Telur', $r->json('material_warnings.0.material'));
        $this->assertSame('Warkop A', $r->json('material_warnings.0.outlet'));
    }

    public function test_calling_consume_twice_does_not_deduct_twice(): void
    {
        $this->recipe($this->nasiGoreng, ['cabai' => [2]]);
        $id = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated()->json('id');

        $again = app(ModifierMaterialConsumption::class)->consumeOrder(Order::find($id), $this->cashier);

        $this->assertSame([], $again);
        $this->assertSame(98.0, $this->balance('cabai'));
    }

    public function test_an_open_tab_never_moves_stock_and_the_final_payment_deducts_once(): void
    {
        $this->recipe($this->nasiGoreng, ['cabai' => [2]]);
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $this->nasiGoreng->id, 2)->assertCreated();
        $this->assertSame(100.0, $this->balance('cabai'), 'tab terbuka tidak menyentuh bahan');

        $this->apiPay($tab, [['method' => 'cash', 'amount' => (float) Order::find($tab)->total_amount]])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame(96.0, $this->balance('cabai'));
    }

    public function test_a_refund_returns_what_was_deducted_even_if_the_recipe_changed_afterwards(): void
    {
        $recipe = $this->recipe($this->nasiGoreng, ['cabai' => [2]]);
        $id = $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated()->json('id');
        $this->assertSame(96.0, $this->balance('cabai'));

        $recipe->lines()->update(['quantity' => 9]);   // diubah SESUDAH jual
        $this->refund($id)->assertOk();

        $this->assertSame(100.0, $this->balance('cabai'), 'kembali sebesar potongan nyata, bukan resep terbaru');
        $this->assertCount(1, $this->consumptionMovements('return'));
    }

    public function test_a_void_by_the_owner_also_returns_recipe_materials(): void
    {
        $this->recipe($this->nasiGoreng, ['ayam' => [1]]);
        $id = $this->checkout([[$this->nasiGoreng, 3, []]])->assertCreated()->json('id');
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->postJson("/api/v1/orders/{$id}/void", ['reason' => 'salah input'])->assertOk();

        $this->assertSame(50.0, $this->balance('ayam'));
    }

    public function test_wasting_a_cooked_line_records_the_recipe_materials_as_waste(): void
    {
        $this->recipe($this->nasiGoreng, ['cabai' => [2]]);
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $lineId = max($this->apiAddLine($tab, $this->nasiGoreng->id, 2)->assertCreated()->json('lines.*.id'));

        app(ModifierMaterialConsumption::class)->wasteLine(OrderItem::find($lineId), $this->cashier);

        $this->assertSame(96.0, $this->balance('cabai'));
        $this->assertCount(1, $this->consumptionMovements('waste'));
    }

    /** Konstitusi V — resep semua varian dimuat sekaligus: jumlah kueri baris resep sama untuk 1 maupun 6 baris. */
    public function test_completing_many_lines_does_not_query_recipes_per_line(): void
    {
        $count = function (int $n): int {
            $lines = [];
            for ($i = 0; $i < $n; $i++) {
                $v = $this->makeRecipeMenu("Menu {$n}-{$i}");
                $this->recipe($v, ['cabai' => [1]]);
                $lines[] = [$v, 1, []];
            }
            // Biaya sudah "diterapkan" supaya applyDue (pembaca resep lain, malas) tidak ikut terhitung.
            Recipe::query()->update(['cost_applied_at' => now()]);
            $queries = 0;
            DB::listen(function ($q) use (&$queries) {
                if (str_contains(strtolower($q->sql), 'from `recipe_lines`')) {
                    $queries++;
                }
            });
            $this->checkout($lines)->assertCreated();

            return $queries;
        };

        $one = $count(1);
        $six = $count(6);

        $this->assertGreaterThan(0, $one);
        $this->assertSame($one, $six, "kueri baris resep harus tetap ({$one} untuk 1 baris, {$six} untuk 6 baris)");
        $this->assertSame(93.0, $this->balance('cabai'), '1 + 6 porsi × 1 gr');
    }

    private function makeRecipeMenu(string $name): ProductVariant
    {
        $product = \App\Models\Product::factory()->create(['name' => $name, 'home_outlet_id' => $this->outletA->id, 'category_id' => \App\Models\Category::factory()->create()->id]);

        return $product->variants()->create(['sku' => strtoupper(\Illuminate\Support\Str::random(10)), 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 3000, 'current_stock' => 500]);
    }

    // 2026-11-02 — tabel bahan per (varian, pilihan modifier).
    private function blockRecipe(array $blocks, string $name = 'Per pilihan'): Recipe
    {
        $data = ['name' => $name, 'yield' => 1, 'effective_from' => now()->toDateString(), 'blocks' => array_map(fn ($b) => [
            'variant_id' => $b[0]->id, 'modifier_option_id' => $b[1] ? $this->opt[$b[1]]->id : null,
            'lines' => collect($b[2])->map(fn ($q, $k) => ['material_id' => $this->mat[$k]->id, 'quantity' => $q])->values()->all(),
        ], $blocks)];

        return app(\App\Services\RecipeService::class)->create($this->nasiGoreng->product, $data, $this->cashier);
    }

    public function test_a_modifier_block_deducts_only_when_its_option_is_ordered_and_scales_with_the_option_quantity(): void
    {
        $this->blockRecipe([
            [$this->nasiGoreng, null, ['cabai' => 1]],
            [$this->nasiGoreng, 'telur', ['cabai' => 3]],
        ]);

        $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated();
        $this->assertSame(98.0, $this->balance('cabai'), 'tanpa pilihan: hanya blok dasar × 2');

        $this->checkout([[$this->nasiGoreng, 2, $this->opts('telur')]])->assertCreated();
        $this->assertSame(98.0 - 2 * (1 + 3), $this->balance('cabai'), 'dengan pilihan: blok dasar + blok pilihan, × qty baris');
    }

    public function test_a_modifier_block_adds_its_cost_to_the_line_but_the_base_block_does_not(): void
    {
        $this->blockRecipe([[$this->nasiGoreng, 'telur', ['telur' => 2]]]);

        $r = $this->checkout([[$this->nasiGoreng, 1, $this->opts('telur')]])->assertCreated();

        $line = OrderItem::find($r->json('items.0.id'));
        // telur Rp2.500: pilihan memetakan 1 butir (087) + blok resep 2 butir = 3 × 2.500 di atas modal varian 7.000.
        $this->assertEquals(7000 + 3 * 2500, (float) $line->cost_price);
    }

    public function test_the_same_variant_and_option_twice_or_a_foreign_option_is_refused(): void
    {
        $dup = fn () => $this->blockRecipe([
            [$this->nasiGoreng, 'telur', ['cabai' => 1]],
            [$this->nasiGoreng, 'telur', ['cabai' => 2]],
        ]);
        try {
            $dup();
            $this->fail('kembar harus ditolak');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('blocks.1.modifier_option_id', $e->errors());
        }

        // 'soto' ada di grup Rasa yang tidak berlaku untuk Nasi Goreng.
        try {
            $this->blockRecipe([[$this->nasiGoreng, 'soto', ['cabai' => 1]]]);
            $this->fail('pilihan asing harus ditolak');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('blocks.0.modifier_option_id', $e->errors());
        }

        $this->assertSame(0, Recipe::query()->count());
    }

    public function test_the_legacy_payload_still_creates_one_base_block_per_variant(): void
    {
        $recipe = app(\App\Services\RecipeService::class)->create($this->nasiGoreng->product, [
            'name' => 'Lama', 'yield' => 1, 'effective_from' => now()->toDateString(),
            'variant_ids' => [$this->nasiGoreng->id], 'lines' => [['material_id' => $this->mat['cabai']->id, 'quantity' => 2]],
        ], $this->cashier);

        $this->assertCount(1, $recipe->blocks);
        $this->assertNull($recipe->blocks[0]->modifier_option_id);
        $this->assertCount(1, $recipe->blocks[0]->lines);
    }
}
