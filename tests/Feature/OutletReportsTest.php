<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\OutletSettlement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 7 (T095–T097) — laporan per outlet TEMPAT penjualan; order lama tetap atribusi penjual lamanya.
 */
class OutletReportsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private $home;

    private Event $event;

    private ProductVariant $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b, $this->home] = $this->makeOutlets(3)->all();
        $this->event = Event::factory()->create(['status' => 'active']);
        $p = Product::factory()->create(['home_outlet_id' => $this->home->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $p->variants()->create(['sku' => 'RPT-1', 'variant_name' => 'R', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 0]);
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function sellAt($outlet, int $qty): Order
    {
        $cashier = $this->assignUser(User::factory()->create(['role' => 'cashier']), [$outlet]);

        return $this->orderAt($this->shiftAt($outlet, $cashier, ['event_id' => $this->event->id]), $this->variant, $qty);
    }

    private function recap(array $headers = []): array
    {
        $this->actingAs($this->owner, 'sanctum');

        return collect($this->withHeaders($headers)->getJson("/api/v1/reports/outlet-settlements?event_id={$this->event->id}")->assertOk()->json('data'))->keyBy('outlet_id')->all();
    }

    public function test_the_recap_groups_post_change_sales_by_the_selling_outlet_not_the_item_home(): void
    {
        $this->sellAt($this->a, 2);
        $this->sellAt($this->b, 3);

        $rows = $this->recap();

        $this->assertSame(['20000.00', 2], [$rows[$this->a->id]['total_sales'], $rows[$this->a->id]['total_units']]);
        $this->assertSame(['30000.00', 3], [$rows[$this->b->id]['total_sales'], $rows[$this->b->id]['total_units']]);
        $this->assertSame('0.00', $rows[$this->home->id]['total_sales'], 'the item home outlet sold nothing itself');
        $this->assertFalse($rows[$this->a->id]['historical']);
    }

    public function test_per_outlet_rows_sum_to_the_all_outlets_total_and_the_filter_narrows(): void
    {
        $this->sellAt($this->a, 2);
        $this->sellAt($this->b, 3);

        $rows = $this->recap();
        $this->assertEquals(50000, collect($rows)->sum(fn ($r) => (float) $r['total_sales']));
        $sales = $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk()->json('totals');
        $this->assertSame('50000.00', $sales['pos_net_sales'] ?? $sales['net_sales']);

        $onlyB = $this->recap(['X-Outlet-Id' => (string) $this->b->id]);
        $this->assertSame([$this->b->id], array_keys($onlyB));
        $filtered = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->json('totals');
        $this->assertSame('30000.00', $filtered['pos_net_sales'] ?? $filtered['net_sales']);
    }

    public function test_legacy_orders_keep_the_former_seller_and_are_labelled_historical_without_double_counting(): void
    {
        $this->sellAt($this->a, 2);                       // post-change: outlet A
        $legacy = $this->sellAt($this->a, 4);             // pretend it predates selling outlets
        Order::withoutGlobalScopes()->whereKey($legacy->id)->update(['outlet_backfilled' => true]);

        $rows = $this->recap();

        $this->assertSame('20000.00', $rows[$this->a->id]['total_sales'], 'post-change sale stays with the selling outlet');
        $this->assertSame('40000.00', $rows[$this->home->id]['total_sales'], 'legacy sale stays with the former seller (item home)');
        $this->assertTrue($rows[$this->home->id]['historical']);
        $this->assertFalse($rows[$this->a->id]['historical']);
        $this->assertEquals(60000, collect($rows)->sum(fn ($r) => (float) $r['total_sales']), 'both kinds, no double counting');
    }

    public function test_the_stored_settlement_snapshot_equals_the_screen_and_event_close_uses_the_same_attribution(): void
    {
        $this->sellAt($this->a, 2);
        $this->sellAt($this->b, 3);
        $rows = $this->recap();

        foreach ([$this->a, $this->b] as $o) {
            $stored = OutletSettlement::where(['event_id' => $this->event->id, 'outlet_id' => $o->id])->firstOrFail();
            $this->assertSame($rows[$o->id]['total_sales'], number_format((float) $stored->total_sales, 2, '.', ''));
        }
        \App\Models\CashierSession::withoutGlobalScopes()->update(['status' => 'closed']); // acara hanya bisa ditutup tanpa shift terbuka
        $this->patchJson("/api/v1/events/{$this->event->id}/status", ['status' => 'closed'])->assertOk();
        $this->assertSame('30000.00', number_format((float) OutletSettlement::where(['event_id' => $this->event->id, 'outlet_id' => $this->b->id])->value('total_sales'), 2, '.', ''));
    }

    public function test_the_profit_report_and_its_transactions_follow_the_same_attribution(): void
    {
        $this->sellAt($this->a, 2);
        $this->sellAt($this->b, 3);
        $this->actingAs($this->owner, 'sanctum');

        $profit = collect($this->getJson("/api/v1/reports/outlet-profit?event_id={$this->event->id}")->assertOk()->json('data'))->keyBy('outlet_id');
        $this->assertSame(['20000.00', '8000.00', '12000.00'], [$profit[$this->a->id]['total_sales'], $profit[$this->a->id]['modal'], $profit[$this->a->id]['gross_profit']]);
        $this->assertArrayNotHasKey($this->home->id, $profit->all());

        $tx = $this->getJson("/api/v1/reports/outlet-settlements/{$this->b->id}/transactions?event_id={$this->event->id}")->assertOk()->json();
        $this->assertCount(1, $tx['transactions'], 'only the order sold at B');
        $this->assertSame(30000.0, round((float) collect($tx['transactions'])->sum(fn ($t) => collect($t['items'] ?? [])->sum('line_total') ?: ($t['line_total'] ?? $t['total'] ?? 0)), 2));
    }

    public function test_a_scoped_user_only_sees_their_outlets_rows_in_the_recap(): void
    {
        $this->sellAt($this->a, 2);
        $this->sellAt($this->b, 3);
        $role = Role::create(['name' => 'Laporan '.uniqid(), 'menu_keys' => ['dashboard', 'reports'], 'all_outlets' => false]);
        $user = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $this->actingAs($user, 'sanctum');

        $ids = collect($this->getJson("/api/v1/reports/outlet-settlements?event_id={$this->event->id}")->assertOk()->json('data'))->pluck('outlet_id')->all();

        $this->assertSame([$this->a->id], $ids);
    }

    public function test_stock_by_outlet_reads_per_outlet_balances(): void
    {
        $this->stockAt($this->variant, $this->a, 12);
        $this->stockAt($this->variant, $this->b, 5);
        $this->actingAs($this->owner, 'sanctum');

        $rows = collect($this->getJson('/api/v1/reports/stock-by-outlet')->assertOk()->json('data'))->keyBy('outlet_id');

        $this->assertSame([12, 5, 0], [$rows[$this->a->id]['total_stock'], $rows[$this->b->id]['total_stock'], $rows[$this->home->id]['total_stock']]);
        $detail = $this->getJson('/api/v1/reports/stock-by-outlet?outlet_id='.$this->a->id)->assertOk()->json();
        $this->assertSame(12, $detail['total_stock']);
    }
}
