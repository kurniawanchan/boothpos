<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\ItemAvailabilityMark;
use App\Models\Order;
use App\Models\ReasonCode;
use App\Models\User;
use App\Support\MenuAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T017) — satu aturan "bisa dipesan": varian, produk/diskontinu, tanda 86.
 */
class MenuAvailabilityTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private \App\Models\CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function mark($product, array $over = []): ItemAvailabilityMark
    {
        return ItemAvailabilityMark::create(array_merge([
            'product_id' => $product->id, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'),
            'marked_by' => $this->cashier->id, 'marked_at' => now(), 'expires_at' => now()->addHours(5),
        ], $over));
    }

    private function checkout(int $variantId)
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantId, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);
    }

    public function test_an_available_variant_is_orderable(): void
    {
        $variant = $this->makeVariant(stock: 5)->fresh('product');

        $this->assertNull(MenuAvailability::unavailableReason($variant));
        $this->assertTrue(MenuAvailability::isOrderable($variant));
    }

    public function test_reasons_in_order_variant_then_product_then_sold_out(): void
    {
        $variant = $this->makeVariant(stock: 5);

        $variant->update(['is_active' => false]);
        $this->assertSame(MenuAvailability::VARIANT_INACTIVE, MenuAvailability::unavailableReason($variant->fresh('product')));

        $variant->update(['is_active' => true]);
        $variant->product->update(['is_active' => false]);
        $this->assertSame(MenuAvailability::PRODUCT_INACTIVE, MenuAvailability::unavailableReason($variant->fresh('product')));

        $variant->product->update(['is_active' => true]);
        $this->mark($variant->product);
        $this->assertSame(MenuAvailability::SOLD_OUT, MenuAvailability::unavailableReason($variant->fresh('product')));
    }

    public function test_a_sold_out_item_is_refused_at_checkout_and_as_a_tab_line_and_nothing_is_recorded(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);
        $this->mark($variant->product);

        $this->checkout($variant->id)->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);

        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $variant->id, 1)->assertStatus(409);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_mark_that_expired_or_was_cleared_no_longer_blocks(): void
    {
        $variant = $this->makeVariant(stock: 5);
        $expired = $this->mark($variant->product, ['expires_at' => now()->subMinute()]);
        $this->assertTrue(MenuAvailability::isOrderable($variant->fresh('product')));

        $expired->update(['expires_at' => now()->addHour()]);
        $this->assertFalse(MenuAvailability::isOrderable($variant->fresh('product')));

        $expired->update(['cleared_at' => now()]);
        $this->assertTrue(MenuAvailability::isOrderable($variant->fresh('product')));
    }

    public function test_a_mark_ends_by_itself_when_the_clock_passes_its_expiry(): void
    {
        $variant = $this->makeVariant(stock: 5);
        $this->mark($variant->product, ['expires_at' => now()->addHours(2)]);
        $this->assertFalse(MenuAvailability::isOrderable($variant->fresh('product')));

        CarbonImmutable::setTestNow(now()->addHours(3));
        \Illuminate\Support\Carbon::setTestNow(now());

        $this->assertTrue(MenuAvailability::isOrderable($variant->fresh('product')));
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_line_already_on_an_open_tab_is_not_touched_when_the_item_is_marked(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $variant->id, 2)->assertCreated();

        $this->mark($variant->product);

        $this->assertSame(1, Order::findOrFail($tab)->items()->count());
        $this->assertSame(2, (int) Order::findOrFail($tab)->items()->value('qty'));
    }

    public function test_sold_out_ids_helper_lists_only_active_marks(): void
    {
        $a = $this->makeVariant(stock: 1)->product;
        $b = $this->makeVariant(stock: 1)->product;
        $this->mark($a);
        $this->mark($b, ['cleared_at' => now()]);

        $this->assertSame([$a->id], MenuAvailability::soldOutProductIds());
    }
}
