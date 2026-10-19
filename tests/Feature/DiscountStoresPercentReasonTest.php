<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 057 / T041 — persen dan alasan diskon disimpan di order dan di baris (FR-012), dihapus bersama diskonnya, dan tidak
 * bisa diisi langsung dari permintaan (kolomnya tidak fillable).
 */
class DiscountStoresPercentReasonTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private CashierSession $shift;

    private int $tab;

    private int $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        // Batas kasir cukup tinggi supaya diskon uji tidak butuh persetujuan.
        $this->cashier->role->forceFill(['discount_limit_pct' => 50])->save();
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
        $this->tab = $this->apiOpenTab($this->shift->id)->json('id');
        $this->line = $this->apiAddLine($this->tab, $this->makeVariant(stock: 100, price: 25000)->id, 2)->json('lines.0.id');
    }

    public function test_an_order_discount_stores_its_percent_and_reason_on_the_order(): void
    {
        $this->postJson("/api/v1/tabs/{$this->tab}/discounts", ['percent' => 11, 'reason_code' => 'member', 'scope' => 'order'])->assertOk();

        $order = Order::findOrFail($this->tab);
        $this->assertSame('11.00', (string) $order->discount_percent);
        $this->assertSame('member', $order->discount_reason_code);
    }

    public function test_a_line_discount_stores_its_percent_and_reason_on_the_line(): void
    {
        $this->postJson("/api/v1/tabs/{$this->tab}/discounts", ['percent' => 5, 'reason_code' => 'member', 'scope' => 'line', 'line_id' => $this->line])->assertOk();

        $item = OrderItem::findOrFail($this->line);
        $this->assertSame('5.00', (string) $item->discount_percent);
        $this->assertSame('member', $item->discount_reason_code);
        $this->assertNull(Order::findOrFail($this->tab)->discount_percent, 'diskon baris tidak mengisi diskon order');
    }

    public function test_removing_the_order_discount_clears_percent_and_reason(): void
    {
        $this->postJson("/api/v1/tabs/{$this->tab}/discounts", ['percent' => 11, 'reason_code' => 'member', 'scope' => 'order'])->assertOk();
        $this->deleteJson("/api/v1/tabs/{$this->tab}/discounts")->assertOk();

        $order = Order::findOrFail($this->tab);
        $this->assertNull($order->discount_percent);
        $this->assertNull($order->discount_reason_code);
        $this->assertSame('0.00', (string) $order->discount_amount);
    }

    public function test_the_tab_payload_shows_the_stored_percent_and_reason_label(): void
    {
        $this->postJson("/api/v1/tabs/{$this->tab}/discounts", ['percent' => 11, 'reason_code' => 'member', 'scope' => 'order'])->assertOk();

        $this->getJson("/api/v1/tabs/{$this->tab}")
            ->assertOk()
            ->assertJsonPath('discount_percent', 11)
            ->assertJsonPath('discount_reason_code', 'member')
            ->assertJsonPath('discount_reason_label', fn ($label) => is_string($label) && $label !== '');
    }

    public function test_the_discount_fields_cannot_be_set_directly_on_the_order(): void
    {
        $this->assertNotContains('discount_percent', (new Order)->getFillable());
        $this->assertNotContains('discount_reason_code', (new Order)->getFillable());
        $this->assertNotContains('discount_percent', (new OrderItem)->getFillable());
    }
}
