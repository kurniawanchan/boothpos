<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 084 US1 — varian tunggal bernama "Standard" tidak muncul di nama baris order (snapshot untuk struk dan KOT);
 * produk bervarian banyak tetap memakai "Produk — Varian". Snapshot lama tidak diubah.
 */
class StandardVariantNameTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private CashierSession $session;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $outlet = Outlet::factory()->create();
        $this->session = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'outlet_id' => $outlet->id]);
        $this->product = Product::factory()->create(['name' => 'Kopi Susu', 'home_outlet_id' => $outlet->id, 'category_id' => Category::factory()->create()->id]);
    }

    private function variant(string $name, string $sku): \App\Models\ProductVariant
    {
        return $this->product->variants()->create(['sku' => $sku, 'variant_name' => $name, 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 10]);
    }

    private function order(int $variantId)
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantId, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ]);
    }

    public function test_a_lone_standard_variant_uses_the_product_name_only(): void
    {
        $v = $this->variant('Standard', 'STDONLY00001');

        $this->order($v->id)->assertCreated()->assertJsonPath('items.0.name_snapshot', 'Kopi Susu');
    }

    public function test_the_match_ignores_case_and_surrounding_spaces(): void
    {
        $v = $this->variant(' standard ', 'STDONLY00002');

        $this->order($v->id)->assertCreated()->assertJsonPath('items.0.name_snapshot', 'Kopi Susu');
    }

    public function test_standard_next_to_another_active_variant_keeps_its_name(): void
    {
        $standard = $this->variant('Standard', 'STDMULTI0001');
        $this->variant('Large', 'STDMULTI0002');

        $this->order($standard->id)->assertCreated()->assertJsonPath('items.0.name_snapshot', 'Kopi Susu — Standard');
    }

    public function test_an_inactive_sibling_does_not_count_as_a_second_variant(): void
    {
        $standard = $this->variant('Standard', 'STDINACT0001');
        $this->variant('Large', 'STDINACT0002')->update(['is_active' => false]);

        $this->order($standard->id)->assertCreated()->assertJsonPath('items.0.name_snapshot', 'Kopi Susu');
    }

    public function test_a_single_variant_with_another_name_keeps_it(): void
    {
        $v = $this->variant('Jumbo', 'STDOTHER0001');

        $this->order($v->id)->assertCreated()->assertJsonPath('items.0.name_snapshot', 'Kopi Susu — Jumbo');
    }

    public function test_the_tab_flow_uses_the_same_rule(): void
    {
        $v = $this->variant('Standard', 'STDTAB000001');
        $tab = $this->apiOpenTab($this->session->id)->json('id');

        $this->apiAddLine($tab, $v->id, 1)->assertCreated();

        $this->assertDatabaseHas('order_items', ['order_id' => $tab, 'name_snapshot' => 'Kopi Susu']);
    }
}
