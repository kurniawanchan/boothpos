<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ModeGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T028) — GET /dashboard/attention ("Perlu tindakan").
 * Jam beku 2026-10-11 05:30 UTC = 12.30 WIB; hari bisnis ini mulai 2026-10-10 17:00 UTC.
 */
class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    private CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 05:30:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->session = $this->makeShift($this->owner);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function attention(): array
    {
        return $this->getJson('/api/v1/dashboard/attention')->assertOk()->json();
    }

    private function item(array $payload, string $kind): array
    {
        return collect($payload['items'])->firstWhere('kind', $kind);
    }

    private function sale(array $payments, int $qty = 1): Order
    {
        $variant = $this->makeVariant(stock: 100, price: 10000);

        return app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => $qty]],
            'payments' => $payments,
        ], $this->owner);
    }

    public function test_every_kind_is_always_present_and_clear_items_are_ok(): void
    {
        $a = $this->attention();

        $this->assertSame(['payments_pending', 'void_after_fire', 'cash_difference', 'low_stock'], array_column($a['items'], 'kind'));
        $this->assertSame(0, $a['count']);
        foreach ($a['items'] as $item) {
            $this->assertSame('ok', $item['severity']);
            $this->assertSame(0, $item['count']);
        }
        $this->assertSame('2026-10-11', $a['business_date']);
    }

    public function test_pending_non_cash_payments_are_counted_but_cash_and_voided_orders_are_not(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);

        $this->sale([['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 10000]]);
        $this->sale([['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 20000]], qty: 2);
        $this->sale([['method' => 'cash', 'amount' => 10000]]);
        $voided = $this->sale([['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 10000]]);
        app(OrderService::class)->void($voided, 'salah input', $this->owner);

        $item = $this->item($this->attention(), 'payments_pending');

        $this->assertSame(2, $item['count']);
        $this->assertSame('30000.00', $item['amount']);
        $this->assertSame('warn', $item['severity']);
        $this->assertSame(['qr_ewallet' => 2], $item['meta']['by_method']);
        $this->assertSame(['route' => 'sales', 'query' => ['pstate' => 'pending']], $item['link']);
    }

    public function test_a_verified_payment_no_longer_counts(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
        $order = $this->sale([['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 10000]]);
        \App\Models\Payment::where('order_id', $order->id)->update(['verification' => 'verified']);

        $this->assertSame(0, $this->item($this->attention(), 'payments_pending')['count']);
    }

    public function test_an_item_cancelled_after_the_ticket_was_sent_counts_with_its_snapshot_value(): void
    {
        $table = \App\Models\DiningTable::factory()->create();
        $tab = $this->apiOpenTab($this->session->id, $table->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $this->makeVariant(stock: 50, price: 15000)->id, 2)->assertCreated();
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();

        $lineId = Order::findOrFail($tab)->items()->value('id');
        $this->deleteJson("/api/v1/tabs/{$tab}/items/{$lineId}", ['reason' => 'pelanggan batal'])->assertOk();

        $item = $this->item($this->attention(), 'void_after_fire');

        $this->assertSame(1, $item['count']);                 // satu baris pesanan dibatalkan (qty 2)
        $this->assertSame('30000.00', $item['amount']);       // 2 × 15.000
        $this->assertSame(0, $item['amount_unknown_count']);
        $this->assertSame('activity-log', $item['link']['route']);
        $this->assertSame('item_cancelled', $item['link']['query']['action']);
    }

    public function test_an_old_cancellation_without_a_price_is_counted_as_amount_unknown(): void
    {
        $item = $this->makeTicketItem(['status' => 'cancelled', 'cancelled_at' => now(), 'qty' => 3, 'unit_price' => null]);

        $a = $this->item($this->attention(), 'void_after_fire');

        $this->assertSame(1, $a['count']);
        $this->assertSame('0.00', $a['amount']);
        $this->assertSame(1, $a['amount_unknown_count']);
    }

    public function test_a_cancellation_from_a_previous_business_day_is_not_counted(): void
    {
        $this->makeTicketItem(['status' => 'cancelled', 'cancelled_at' => CarbonImmutable::parse('2026-10-10 16:59:00'), 'qty' => 1, 'unit_price' => 5000]);

        $this->assertSame(0, $this->item($this->attention(), 'void_after_fire')['count']);
    }

    public function test_only_shifts_closed_today_beyond_the_tolerance_count(): void
    {
        $make = fn (float $diff, string $closedAt) => CashierSession::factory()->create([
            'user_id' => $this->owner->id, 'status' => 'closed',
            'closed_at' => $closedAt, 'cash_difference' => $diff,
        ]);
        $make(-23500, '2026-10-11 04:00:00');   // di luar toleransi, hari ini
        $make(3000, '2026-10-11 04:10:00');     // dalam toleransi 5.000
        $make(-90000, '2026-10-10 10:00:00');   // hari lain

        $item = $this->item($this->attention(), 'cash_difference');

        $this->assertSame(1, $item['count']);
        $this->assertSame('-23500.00', $item['amount']);
        $this->assertSame('5000.00', $item['meta']['tolerance']);
        $this->assertSame('critical', $item['severity']);
    }

    public function test_the_tolerance_setting_is_honoured(): void
    {
        Setting::updateOrCreate(['key' => 'cash_difference_tolerance'], ['value' => '1000', 'type' => 'decimal']);
        CashierSession::factory()->create([
            'user_id' => $this->owner->id, 'status' => 'closed',
            'closed_at' => '2026-10-11 04:00:00', 'cash_difference' => 3000,
        ]);

        $this->assertSame(1, $this->item($this->attention(), 'cash_difference')['count']);
    }

    public function test_low_stock_matches_the_existing_low_stock_endpoint(): void
    {
        $this->makeVariant(stock: 2, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->makeVariant(stock: 99, price: 1000, variantOverrides: ['low_stock_alert' => 5]);

        $item = $this->item($this->attention(), 'low_stock');
        $endpoint = $this->getJson('/api/v1/stock/low')->assertOk()->json('data');

        $this->assertSame(count($endpoint), $item['count']);
        $this->assertSame(1, $item['count']);
        $this->assertSame(['route' => 'stock', 'query' => ['low' => 1]], $item['link']);
    }

    public function test_the_top_level_count_is_the_number_of_items_that_need_action(): void
    {
        $this->makeVariant(stock: 1, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->sale([['method' => 'qr_ewallet', 'channel_id' => PaymentChannel::factory()->create(['type' => 'qr_ewallet'])->id, 'amount' => 10000]]);

        $this->assertSame(2, $this->attention()['count']);
    }

    public function test_links_are_omitted_for_a_role_that_cannot_open_the_destination(): void
    {
        $this->makeVariant(stock: 1, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $role = Role::factory()->create(['menu_keys' => ['dashboard']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $a = $this->attention();

        foreach ($a['items'] as $item) {
            $this->assertNull($item['link'], $item['kind']);
        }
        $this->assertSame(1, $this->item($a, 'low_stock')['count']); // butir tetap tampil, hanya tanpa tautan
    }

    public function test_demo_and_live_data_never_mix(): void
    {
        $this->makeVariant(stock: 1, price: 1000, variantOverrides: ['low_stock_alert' => 5]);

        ModeGate::runAs(ModeGate::DEMO, function () {
            $this->assertSame(0, $this->item($this->attention(), 'low_stock')['count']);
        });
    }

    public function test_a_role_without_the_dashboard_menu_is_refused(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->getJson('/api/v1/dashboard/attention')->assertForbidden();
    }
}
