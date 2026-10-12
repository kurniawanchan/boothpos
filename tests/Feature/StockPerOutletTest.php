<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\VariantOutletStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 5 (T070) — stok adalah saldo per outlet: penjualan/retur/pre-order bergerak di outlet transaksinya.
 */
class StockPerOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private ProductVariant $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $p->variants()->create(['sku' => 'STK-1', 'variant_name' => 'Regular', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 0, 'low_stock_alert' => 2]);
        $this->stockAt($this->variant, $this->a, 5);
        $this->stockAt($this->variant, $this->b, 2);
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function balance($outlet): int
    {
        return (int) VariantOutletStock::where(['variant_id' => $this->variant->id, 'outlet_id' => is_object($outlet) ? $outlet->id : $outlet])->value('current_stock');
    }

    private function sell($outlet, int $qty = 1)
    {
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlet]);
        $shift = $this->shiftAt($outlet, $cashier);
        $this->actingAs($cashier, 'sanctum');

        return $this->postJson('/api/v1/orders', [
            'session_id' => $shift->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]], 'payments' => [['method' => 'cash', 'amount' => 10000 * $qty]],
        ]);
    }

    public function test_a_sale_moves_only_the_balance_of_its_outlet_and_the_cached_total(): void
    {
        $this->sell($this->b)->assertCreated();

        $this->assertSame(5, $this->balance($this->a));
        $this->assertSame(1, $this->balance($this->b));
        $this->assertSame(6, $this->variant->fresh()->current_stock);
        $m = \App\Models\StockMovement::where('type', 'sale')->firstOrFail();
        $this->assertSame($this->b->id, $m->outlet_id);
        $this->assertSame([2, 1], [$m->stock_before, $m->stock_after], 'the ledger records the OUTLET balance');
    }

    public function test_the_last_unit_is_refused_at_one_outlet_and_still_sellable_at_the_other_naming_the_outlet(): void
    {
        $this->sell($this->b, 2)->assertCreated();                       // B -> 0
        $refused = $this->sell($this->b)->assertStatus(409);
        $this->assertStringContainsString($this->b->name, (string) $refused->json('message'));

        $this->sell($this->a)->assertCreated();                          // A still has 5
        $this->assertSame(0, $this->balance($this->b));
        $this->assertSame(4, $this->balance($this->a));
    }

    public function test_voiding_returns_stock_to_the_order_outlet(): void
    {
        $order = $this->sell($this->b)->assertCreated()->json();
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'salah input'])->assertOk();

        $this->assertSame(2, $this->balance($this->b));
        $this->assertSame(5, $this->balance($this->a));
        $this->assertSame(7, $this->variant->fresh()->current_stock);
    }

    public function test_untracked_items_never_touch_any_balance(): void
    {
        $this->variant->update(['track_stock' => false]);

        $this->sell($this->b)->assertCreated();

        $this->assertSame(2, $this->balance($this->b));
        $this->assertSame(7, $this->variant->fresh()->current_stock);
    }

    public function test_an_adjustment_applies_to_the_chosen_permitted_outlet(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson('/api/v1/stock/adjustments', ['reason' => 'opname', 'outlet_id' => $this->b->id, 'items' => [['variant_id' => $this->variant->id, 'qty_change' => 3]]])->assertCreated();

        $this->assertSame(5, $this->balance($this->b));
        $this->assertSame(5, $this->balance($this->a));
        $this->assertSame(10, $this->variant->fresh()->current_stock);
    }

    public function test_an_adjustment_needs_an_outlet_when_several_are_visible_and_a_foreign_one_is_forbidden(): void
    {
        $role = Role::create(['name' => 'Stok '.uniqid(), 'menu_keys' => ['dashboard', 'stock'], 'all_outlets' => false]);
        $both = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a, $this->b]);
        $onlyA = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $body = ['reason' => 'opname', 'items' => [['variant_id' => $this->variant->id, 'qty_change' => 1]]];

        $this->actingAs($both, 'sanctum');
        $this->postJson('/api/v1/stock/adjustments', $body)->assertStatus(422)->assertJsonValidationErrors('outlet_id');

        $this->actingAs($onlyA, 'sanctum');
        $this->postJson('/api/v1/stock/adjustments', $body + ['outlet_id' => $this->b->id])->assertForbidden();
        $this->postJson('/api/v1/stock/adjustments', $body)->assertCreated(); // one outlet: no extra step
        $this->assertSame(6, $this->balance($this->a));
    }

    public function test_a_negative_adjustment_cannot_drive_an_outlet_below_zero(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson('/api/v1/stock/adjustments', ['reason' => 'x', 'outlet_id' => $this->b->id, 'items' => [['variant_id' => $this->variant->id, 'qty_change' => -3]]])->assertStatus(422);

        $this->assertSame(2, $this->balance($this->b));
    }

    public function test_movements_carry_and_filter_by_outlet(): void
    {
        $this->sell($this->b)->assertCreated();
        $this->actingAs($this->owner, 'sanctum');

        $all = $this->getJson('/api/v1/stock/movements?variant_id='.$this->variant->id)->assertOk()->json('data');
        $this->assertContains($this->b->code, collect($all)->pluck('outlet_code')->all());

        $onlyA = $this->getJson('/api/v1/stock/movements?outlet_id='.$this->a->id)->assertOk()->json('data');
        $this->assertSame([$this->a->code], collect($onlyA)->pluck('outlet_code')->unique()->values()->all());

        $narrowed = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson('/api/v1/stock/movements')->assertOk()->json('data');
        $this->assertSame([$this->b->code], collect($narrowed)->pluck('outlet_code')->unique()->values()->all());
    }

    public function test_a_preorder_arrival_and_handover_use_the_preorders_outlet(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $customer = Customer::factory()->create();
        $created = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup', 'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json();

        $this->patchJson("/api/v1/preorders/{$created['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$created['id']}/status", ['status' => 'arrived'])->assertOk();
        $this->assertSame(4, $this->balance($this->b));
        $this->assertSame(5, $this->balance($this->a));
    }
}
