<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 093 US1 (FR-002, FR-007, contracts/api-changes.md §2) — GET /recipe-ingredients: varian produk bahan yang boleh dipilih
 * di resep BARU, dengan stok tersedia di outlet yang berlaku. Outlet hanya dari konteks server, tidak dari parameter.
 */
class IngredientPickerTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $a;

    private Outlet $b;

    private int $seq = 0;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Outlet::factory()->create(['code' => 'WJA']);
        $this->b = Outlet::factory()->create(['code' => 'WJB']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function ingredient(string $name, array $product = [], array $variant = [], int $stockA = 0): ProductVariant
    {
        $p = Product::factory()->create(array_merge(['name' => $name, 'use_as_ingredient' => true, 'ingredient_unit' => 'pcs'], $product));
        $v = $p->variants()->create(['sku' => 'ING-'.++$this->seq, 'variant_name' => 'Standard', 'sell_price' => 3000, 'cost_price' => 1000, 'current_stock' => 0] + $variant);
        if ($stockA > 0) {
            app(StockService::class)->applyMovement($v, $this->a->id, 'adjustment', $stockA);
        }

        return $v;
    }

    private function list(array $query = [], ?Outlet $outlet = null)
    {
        return $this->getJson('/api/v1/recipe-ingredients?'.http_build_query($query), $outlet ? ['X-Outlet-Id' => (string) $outlet->id] : []);
    }

    public function test_it_lists_only_variants_that_can_be_picked_for_a_new_recipe(): void
    {
        $telur = $this->ingredient('Telur', stockA: 200);
        $this->ingredient('Gula', ['use_as_ingredient' => false]);                  // tak bertanda
        $this->ingredient('Susu', [], ['is_active' => false]);                      // varian nonaktif
        $this->ingredient('Air', [], ['track_stock' => false]);                     // tak melacak stok
        $this->ingredient('Kopi', ['stock_source' => Product::STOCK_MODIFIER]);     // stok dari modifier
        $this->ingredient('Roti', ['is_active' => false]);                          // produk nonaktif

        $driven = $this->ingredient('Nasi Telur');                                  // sudah beresep → menu, bukan bahan
        $recipe = Recipe::create(['product_id' => $driven->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->toDateString(), 'created_by' => $this->owner->id]);
        $recipe->variants()->sync([$driven->id]);

        $names = collect($this->list([], $this->a)->assertOk()->json('data'))->pluck('product_name')->all();

        $this->assertSame(['Telur'], $names);
        $row = $this->list([], $this->a)->json('data.0');
        $this->assertSame($telur->id, $row['variant_id']);
        $this->assertSame($telur->product_id, $row['product_id']);
        $this->assertSame('Standard', $row['variant_name']);
        $this->assertSame($telur->sku, $row['sku']);
        $this->assertSame('pcs', $row['unit']);
        $this->assertTrue($row['is_active']);
    }

    public function test_it_excludes_the_product_being_edited_and_supports_search(): void
    {
        $telur = $this->ingredient('Telur');
        $this->ingredient('Indomie Goreng');

        $this->assertSame(['Indomie Goreng'], array_column($this->list(['exclude_product_id' => $telur->product_id], $this->a)->json('data'), 'product_name'));
        $this->assertSame(['Telur'], array_column($this->list(['search' => 'tel'], $this->a)->json('data'), 'product_name'));
    }

    public function test_availability_is_the_balance_at_the_context_outlet_minus_holds(): void
    {
        $telur = $this->ingredient('Telur', stockA: 200);
        app(StockService::class)->applyMovement($telur, $this->b->id, 'adjustment', 7);

        $a = $this->list([], $this->a)->json('data.0');
        $this->assertSame([200, 0, 200], [$a['on_hand'], $a['held'], $a['available']]);

        $b = $this->list([], $this->b)->json('data.0');
        $this->assertSame([7, 0, 7], [$b['on_hand'], $b['held'], $b['available']]);
    }

    public function test_the_outlet_never_comes_from_a_parameter(): void
    {
        $telur = $this->ingredient('Telur', stockA: 200);
        app(StockService::class)->applyMovement($telur, $this->b->id, 'adjustment', 7);

        // Header memilih A; parameter mencoba B dan harus diabaikan.
        $row = $this->list(['outlet_id' => $this->b->id], $this->a)->json('data.0');

        $this->assertSame(200, $row['on_hand']);
    }

    public function test_without_a_single_outlet_stock_is_unknown_rather_than_summed(): void
    {
        $this->ingredient('Telur', stockA: 200);

        $row = $this->list()->assertOk()->json('data.0');

        $this->assertNull($row['on_hand']);
        $this->assertNull($row['available']);
    }

    public function test_a_role_without_the_products_menu_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->list([], $this->a)->assertForbidden();
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_variants(): void
    {
        $this->ingredient('Telur', stockA: 5);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list([], $this->a)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->list([], $this->a); // memanaskan cache izin/menu
        $few = $count();

        foreach (range(1, 12) as $i) {
            $this->ingredient('Bahan '.$i, stockA: $i);
        }
        $many = $count();

        $this->assertSame($few, $many, 'jumlah kueri tetap terhadap banyaknya varian (Konstitusi V)');
    }
}
