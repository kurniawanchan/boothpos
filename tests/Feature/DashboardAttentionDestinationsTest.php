<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T030, SC-003) — setiap butir "Perlu tindakan" mendarat di daftar yang jumlah barisnya
 * sama dengan angka pada butir itu.
 */
class DashboardAttentionDestinationsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pending_payments_equal_the_rows_of_the_sales_page_filtered_by_pending(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 05:30:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $session = $this->makeShift($owner);
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);

        foreach ([10000, 20000] as $amount) {
            app(OrderService::class)->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $this->makeVariant(stock: 10, price: $amount)->id, 'qty' => 1]],
                'payments' => [['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => $amount]],
            ], $owner);
        }
        app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->makeVariant(stock: 10, price: 5000)->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ], $owner);

        $item = collect($this->getJson('/api/v1/dashboard/attention')->json('items'))->firstWhere('kind', 'payments_pending');
        $rows = collect($this->getJson('/api/v1/reports/sales?group_by=day')->json('transactions'))
            ->where('payment_state', 'pending');

        $this->assertSame(2, $item['count']);
        $this->assertSame($item['count'], $rows->count());
    }

    public function test_voids_after_fire_equal_the_item_cancelled_rows_of_the_activity_log_for_that_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 05:30:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $session = $this->makeShift($owner);

        foreach ([1, 2] as $_) {
            $tab = $this->apiOpenTab($session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
            $this->apiAddLine($tab, $this->makeVariant(stock: 20, price: 9000)->id, 3)->assertCreated();
            $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
            $line = Order::findOrFail($tab)->items()->value('id');
            $this->deleteJson("/api/v1/tabs/{$tab}/items/{$line}", ['reason' => 'batal'])->assertOk();
        }

        $item = collect($this->getJson('/api/v1/dashboard/attention')->json('items'))->firstWhere('kind', 'void_after_fire');
        $logs = $this->getJson('/api/v1/activity-logs?action=item_cancelled&date=2026-10-11&per_page=100')->assertOk()->json('data');

        $this->assertSame(2, $item['count']);
        $this->assertCount($item['count'], $logs);
        // Hari bisnis lain tidak ikut.
        $this->assertCount(0, $this->getJson('/api/v1/activity-logs?action=item_cancelled&date=2026-10-10')->json('data'));
    }

    public function test_low_stock_equals_the_stock_low_endpoint(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->makeVariant(stock: 1, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->makeVariant(stock: 2, price: 1000, variantOverrides: ['low_stock_alert' => 5]);

        $item = collect($this->getJson('/api/v1/dashboard/attention')->json('items'))->firstWhere('kind', 'low_stock');

        $this->assertSame($item['count'], count($this->getJson('/api/v1/stock/low')->json('data')));
    }
}
