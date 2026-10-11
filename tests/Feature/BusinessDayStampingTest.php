<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T010) — `completed_at` dan `business_date` terpasang di SETIAP jalur yang
 * membuat atau menyelesaikan order, plus pembayaran dan shift. Satu tes per jalur (research.md:
 * "tab settlement stamping missed on one of three code paths").
 */
class BusinessDayStampingTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private \App\Models\CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        // 23.30 WIB tanggal 10 Okt = 16.30 UTC.
        CarbonImmutable::setTestNow('2026-10-10 16:30:00');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_pos_checkout_is_completed_and_dated_at_creation(): void
    {
        $variant = $this->makeVariant(stock: 10, price: 10000);

        $order = app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->cashier)->fresh();

        $this->assertSame('2026-10-10', $order->business_date);
        $this->assertNotNull($order->completed_at);
        $this->assertSame('2026-10-10 16:30:00', $order->completed_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_payments_and_shifts_carry_their_own_business_date(): void
    {
        $variant = $this->makeVariant(stock: 10, price: 10000);
        $order = app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->cashier);

        $this->assertSame('2026-10-10', Payment::where('order_id', $order->id)->first()->business_date);
        $this->assertSame('2026-10-10', $this->session->fresh()->business_date);
    }

    public function test_an_open_tab_carries_the_day_it_was_opened_and_no_completion_instant(): void
    {
        $table = DiningTable::factory()->create();
        $tab = Order::findOrFail($this->apiOpenTab($this->session->id, $table->id)->assertCreated()->json('id'));

        $this->assertSame('open', $tab->status);
        $this->assertSame('2026-10-10', $tab->business_date);
        $this->assertNull($tab->completed_at);
    }

    public function test_settling_a_tab_after_midnight_moves_it_to_the_settlement_day(): void
    {
        $table = DiningTable::factory()->create();
        $tabId = $this->apiOpenTab($this->session->id, $table->id)->assertCreated()->json('id');
        $this->apiAddLine($tabId, $this->makeVariant(stock: 10, price: 20000)->id, 1)->assertCreated();

        CarbonImmutable::setTestNow('2026-10-10 17:20:00'); // 00.20 WIB tanggal 11
        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 20000]])->assertOk()->assertJsonPath('status', 'completed');

        $tab = Order::findOrFail($tabId);
        $this->assertSame('2026-10-11', $tab->business_date);
        $this->assertSame('2026-10-10 17:20:00', $tab->completed_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_partial_payment_does_not_complete_or_move_the_tab(): void
    {
        $table = DiningTable::factory()->create();
        $tabId = $this->apiOpenTab($this->session->id, $table->id)->assertCreated()->json('id');
        $this->apiAddLine($tabId, $this->makeVariant(stock: 10, price: 20000)->id, 1)->assertCreated();

        CarbonImmutable::setTestNow('2026-10-10 17:20:00');
        $this->apiPay($tabId, [['method' => 'cash', 'amount' => 5000]])->assertOk()->assertJsonPath('status', 'open');

        $tab = Order::findOrFail($tabId);
        $this->assertSame('2026-10-10', $tab->business_date);
        $this->assertNull($tab->completed_at);
    }

    public function test_a_split_off_paid_order_is_completed_when_it_is_created(): void
    {
        $table = DiningTable::factory()->create();
        $tabId = $this->apiOpenTab($this->session->id, $table->id)->assertCreated()->json('id');
        $this->apiAddLine($tabId, $this->makeVariant(stock: 10, price: 15000)->id, 1)->assertCreated();
        $this->apiAddLine($tabId, $this->makeVariant(stock: 10, price: 15000)->id, 1)->assertCreated();

        CarbonImmutable::setTestNow('2026-10-10 17:20:00');
        $this->postJson("/api/v1/tabs/{$tabId}/split", [
            'client_ref' => (string) Str::uuid(),
            'items' => [['item_id' => Order::findOrFail($tabId)->items()->orderBy('id')->value('id'), 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ])->assertCreated();

        $paid = Order::where('split_from_order_id', $tabId)->firstOrFail();
        $this->assertSame('completed', $paid->status);
        $this->assertSame('2026-10-11', $paid->business_date);
        $this->assertNotNull($paid->completed_at);
        // Tab asli masih terbuka dan tidak berpindah.
        $this->assertSame('2026-10-10', Order::findOrFail($tabId)->business_date);
    }

    public function test_a_voided_order_keeps_its_last_stamped_date(): void
    {
        $variant = $this->makeVariant(stock: 10, price: 10000);
        $order = app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->cashier);

        CarbonImmutable::setTestNow('2026-10-12 03:00:00');
        app(OrderService::class)->void($order, 'salah input', User::factory()->create(['role' => 'owner']));

        $order = $order->fresh();
        $this->assertSame('voided', $order->status);
        $this->assertSame('2026-10-10', $order->business_date);
    }

    public function test_the_client_can_never_supply_the_date_or_completion_instant(): void
    {
        $variant = $this->makeVariant(stock: 10, price: 10000);

        $id = $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
            'business_date' => '2020-01-01', 'completed_at' => '2020-01-01 00:00:00', 'outlet_id' => 99,
        ])->assertCreated()->json('id');

        $order = Order::findOrFail($id);
        $this->assertSame('2026-10-10', $order->business_date);
        $this->assertSame('2026-10-10', $order->completed_at->utc()->toDateString());
    }
}
