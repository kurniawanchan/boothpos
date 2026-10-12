<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\ItemAvailabilityMark;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Models\VariantOutletPrice;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 4 (T065/T066) — layar pemesanan memakai outlet SHIFT: menu (dibawa/tidak), harga khusus, tanda 86, pre-order.
 */
class OrderingScreensOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private Product $kopi;

    private Product $teh;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $cat = Category::factory()->create();
        $this->kopi = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => $cat->id, 'name' => 'Kopi']);
        $this->kopi->variants()->create(['sku' => 'ORD-KOPI-1', 'variant_name' => 'Regular', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 50]);
        $this->teh = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => $cat->id, 'name' => 'Teh']);
        $this->teh->variants()->create(['sku' => 'ORD-TEH-1', 'variant_name' => 'Regular', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 50]);
        // Kasir ditugaskan ke dua outlet; shiftnya di B.
        $this->cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a, $this->b]);
        $this->shiftAt($this->b, $this->cashier);
        app(OutletMenuService::class)->setCarried($this->teh, [$this->a->id]); // Teh TIDAK dibawa B
        VariantOutletPrice::create(['variant_id' => $this->kopi->variants()->first()->id, 'outlet_id' => $this->b->id, 'price' => 27000]);
        foreach ([$this->kopi, $this->teh] as $p) {
            $this->stockAt($p->variants()->first(), $this->b, 50); // stok per outlet: shift di B menjual dari saldo B
        }
        $this->actingAs($this->cashier, 'sanctum');
    }

    private function names(array $json): array
    {
        return collect($json['data'])->pluck('name')->sort()->values()->all();
    }

    public function test_the_pos_list_hides_items_not_carried_at_the_shift_outlet_and_carries_its_prices(): void
    {
        $plain = $this->getJson('/api/v1/products?with_variants=1')->assertOk()->json();
        $this->assertSame(['Kopi', 'Teh'], $this->names($plain), 'without pos=1 the list is unchanged');

        $pos = $this->getJson('/api/v1/products?with_variants=1&pos=1')->assertOk()->json();
        $this->assertSame(['Kopi'], $this->names($pos));
        $this->assertSame('27000.00', $pos['data'][0]['variants'][0]['outlet_price']);
    }

    public function test_the_shift_outlet_wins_over_the_selection_header_on_the_pos_list(): void
    {
        $pos = $this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/products?with_variants=1&pos=1')->assertOk()->json();

        $this->assertSame(['Kopi'], $this->names($pos), 'priced and filtered for the SHIFT outlet (B), not the header outlet (A)');
        $this->assertSame('27000.00', $pos['data'][0]['variants'][0]['outlet_price']);
    }

    public function test_variant_lookup_follows_the_pos_flag(): void
    {
        $all = collect($this->getJson('/api/v1/variants/lookup?q=ORD')->assertOk()->json('data'))->pluck('sku')->sort()->values()->all();
        $this->assertSame(['ORD-KOPI-1', 'ORD-TEH-1'], $all);

        $pos = $this->getJson('/api/v1/variants/lookup?q=ORD&pos=1')->assertOk()->json('data');
        $this->assertSame(['ORD-KOPI-1'], collect($pos)->pluck('sku')->all());
        $this->assertSame('27000.00', $pos[0]['outlet_price']);
    }

    public function test_the_availability_poll_for_pos_uses_the_shift_outlet(): void
    {
        $reason = (int) ReasonCode::where('category', 'sold_out')->value('id');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/v1/products/{$this->kopi->id}/sold-out", ['reason_code_id' => $reason, 'outlet_ids' => [$this->a->id]])->assertCreated();
        $this->actingAs($this->cashier, 'sanctum');

        $forA = $this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/menu/availability')->assertOk()->json('sold_out');
        $this->assertCount(1, $forA);

        $posPoll = $this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/menu/availability?pos=1')->assertOk()->json('sold_out');
        $this->assertCount(0, $posPoll, 'the shift is at B, where Kopi is not sold out');
    }

    public function test_a_sale_uses_the_shift_outlet_price_and_the_recorded_snapshot_never_changes(): void
    {
        $variant = $this->kopi->variants()->first();
        $shift = \App\Models\CashierSession::withoutGlobalScopes()->where('user_id', $this->cashier->id)->first();

        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $shift->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 27000]],
        ])->assertCreated()->json();
        $this->assertSame('27000.00', number_format((float) $order['total_amount'], 2, '.', ''));

        VariantOutletPrice::where('outlet_id', $this->b->id)->update(['price' => 30000]);
        $variant->update(['sell_price' => 25000]);

        $this->getJson("/api/v1/orders/{$order['id']}")->assertOk();
        $this->assertSame(27000.0, (float) \App\Models\OrderItem::withoutGlobalScopes()->where('order_id', $order['id'])->value('sell_price'));
    }

    public function test_a_forged_order_for_an_item_not_carried_at_the_shift_outlet_is_refused(): void
    {
        $shift = \App\Models\CashierSession::withoutGlobalScopes()->where('user_id', $this->cashier->id)->first();

        $this->postJson('/api/v1/orders', [
            'session_id' => $shift->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->teh->variants()->first()->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertStatus(409);
        $this->assertSame(0, \App\Models\Order::withoutGlobalScopes()->count());
    }

    public function test_a_86_at_the_shift_outlet_blocks_it_but_not_at_the_other_outlet(): void
    {
        $variant = $this->kopi->variants()->first();
        ItemAvailabilityMark::create([
            'product_id' => $this->kopi->id, 'outlet_id' => $this->b->id, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'),
            'marked_by' => User::factory()->create(['role' => 'owner'])->id, 'marked_at' => now(), 'expires_at' => now()->addHours(5),
        ]);
        $shiftB = \App\Models\CashierSession::withoutGlobalScopes()->where('user_id', $this->cashier->id)->first();
        $cashierA = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$this->a]);
        $shiftA = $this->shiftAt($this->a, $cashierA);

        $body = fn ($shift, $amount) => ['session_id' => $shift->id, 'local_ref' => (string) \Illuminate\Support\Str::uuid(), 'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => $amount]]];

        $this->postJson('/api/v1/orders', $body($shiftB, 27000))->assertStatus(409);
        $this->actingAs($cashierA, 'sanctum');
        $this->postJson('/api/v1/orders', $body($shiftA, 20000))->assertCreated();
    }

    public function test_a_preorder_for_an_item_not_carried_at_the_creating_outlet_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $this->withHeader('X-Outlet-Id', (string) $this->b->id);

        $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->teh->variants()->first()->id, 'qty' => 1]],
        ])->assertStatus(422);

        $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->kopi->variants()->first()->id, 'qty' => 1]],
        ])->assertCreated();
    }

    public function test_a_split_preorder_keeps_the_outlet_of_the_original(): void
    {
        $customer = Customer::factory()->create();
        $variants = [$this->kopi->variants()->first()->id];
        $second = $this->kopi->variants()->create(['sku' => 'ORD-KOPI-2', 'variant_name' => 'Large', 'sell_price' => 25000, 'cost_price' => 9000, 'current_stock' => 5]);
        $created = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variants[0], 'qty' => 2], ['variant_id' => $second->id, 'qty' => 1]],
        ])->assertCreated()->json();
        $items = $this->getJson("/api/v1/preorders/{$created['id']}")->assertOk()->json('items');

        // Dipecah TANPA header (kasir punya dua outlet → konteks akan menstempel outlet pertama, A): pecahan harus tetap di B.
        $response = $this->postJson("/api/v1/preorders/{$created['id']}/split", ['mode' => 'items', 'items' => [['item_id' => $items[1]['id'], 'qty' => 1]]]);
        $response->assertSuccessful();

        $outlets = \App\Models\Preorder::withoutGlobalScopes()->pluck('outlet_id')->unique()->values()->all();
        $this->assertSame([$this->b->id], $outlets);
    }
}
