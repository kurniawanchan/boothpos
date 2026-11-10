<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\VariantOutletStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\IngredientFixtures;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 093 US5 (FR-023, FR-026, FR-027) — riwayat satu produk bahan memuat SEMUA sumber (jual langsung, pemakaian resep,
 * pembelian, koreksi, pembatalan) pada satu saldo, dan tiap baris pemakaian resep menyebut menu serta nomor pesanannya.
 */
class IngredientHistoryTest extends TestCase
{
    use IngredientFixtures, ModifierMaterialFixtures, OverrideFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->buildIngredientWorld(['mie' => 100, 'telur' => 100]);
        $this->ingredientRecipe($this->nasiGoreng, ['telur' => 1]);
    }

    private function history(array $query = []): array
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        return $this->getJson('/api/v1/stock/movements?'.http_build_query(['variant_id' => $this->ing['telur']->id, 'per_page' => 100] + $query))
            ->assertOk()->json('data');
    }

    public function test_a_recipe_use_row_names_the_menu_and_the_order_number(): void
    {
        $orderId = $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated()->json('id');
        $order = Order::findOrFail($orderId);

        $row = collect($this->history())->firstWhere('type', 'recipe_sale');

        $this->assertSame(-2, $row['qty_change']);
        $this->assertSame($this->ing['telur']->sku, $row['sku']);
        $this->assertSame('order', $row['reference']['type']);
        $this->assertSame($orderId, $row['reference']['id']);
        $this->assertSame($order->order_number, $row['reference']['number']);
        $this->assertSame(OrderItem::where('order_id', $orderId)->value('name_snapshot'), $row['reference']['menu']);
    }

    public function test_one_history_shows_every_source_on_one_balance(): void
    {
        $this->checkout([[$this->ing['telur'], 3, []]])->assertCreated();                       // jual langsung
        $orderId = $this->checkout([[$this->nasiGoreng, 2, []]])->assertCreated()->json('id');   // resep
        app(\App\Services\StockService::class)->applyMovement(variant: $this->ing['telur'], outletId: $this->outletA->id, type: 'purchase', qtyChange: 20);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->postJson('/api/v1/stock/adjustments', ['outlet_id' => $this->outletA->id, 'reason' => 'pecah', 'items' => [['variant_id' => $this->ing['telur']->id, 'qty_change' => -5]]])->assertSuccessful();
        $this->postJson("/api/v1/orders/{$orderId}/void", ['reason' => 'salah input'])->assertOk();   // pemulihan

        $rows = collect($this->history());
        $types = $rows->pluck('type')->unique()->values()->all();

        foreach (['adjustment', 'sale', 'recipe_sale', 'recipe_return', 'purchase'] as $t) {
            $this->assertContains($t, $types, "sumber {$t} muncul di satu riwayat");
        }
        $balance = (int) VariantOutletStock::where(['variant_id' => $this->ing['telur']->id, 'outlet_id' => $this->outletA->id])->value('current_stock');
        $this->assertSame(100 - 3 + 20 - 5, $balance, '100 awal − 3 langsung (resep dipulihkan) + 20 beli − 5 koreksi');
        $this->assertSame(1, VariantOutletStock::where(['variant_id' => $this->ing['telur']->id, 'outlet_id' => $this->outletA->id])->count(), 'satu saldo per varian per outlet');
        $this->assertSame($balance, (int) $this->ing['telur']->fresh()->current_stock);
    }

    public function test_the_type_filter_accepts_the_recipe_types(): void
    {
        $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated();

        $only = $this->history(['type' => 'recipe_sale']);

        $this->assertNotEmpty($only);
        $this->assertSame(['recipe_sale'], array_values(array_unique(array_column($only, 'type'))));
    }

    public function test_a_recipe_row_whose_order_line_is_gone_has_no_reference_instead_of_a_wrong_one(): void
    {
        $orderId = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated()->json('id');
        \Illuminate\Support\Facades\DB::table('stock_movements')->where('type', 'recipe_sale')->update(['reference_id' => 999999]);

        $row = collect($this->history())->firstWhere('type', 'recipe_sale');

        $this->assertNull($row['reference']);
        $this->assertNotNull($orderId);
    }
}
