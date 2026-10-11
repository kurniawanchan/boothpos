<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T036) — GET /dashboard/orders. Jam beku: 2026-10-11 05:30 UTC (12.30 WIB).
 */
class DashboardOrdersTest extends TestCase
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

    private function sale(array $payments, float $price = 10000, string $name = 'Kopi'): Order
    {
        $variant = $this->makeVariant(stock: 100, price: $price, variantOverrides: ['variant_name' => $name]);

        return app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => $payments,
        ], $this->owner);
    }

    private function partial(): Order
    {
        $variant = $this->makeVariant(stock: 100, price: 30000);
        $customer = \App\Models\Customer::factory()->create();

        return app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->owner);
    }

    private function tab(): Order
    {
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $this->makeVariant(stock: 50, price: 20000)->id, 1)->assertCreated();

        return Order::findOrFail($tab);
    }

    private function listing(string $query = ''): array
    {
        return $this->getJson('/api/v1/dashboard/orders'.($query ? '?'.$query : ''))->assertOk()->json();
    }

    /** @return array<string,Order> */
    private function seedEveryStatus(): array
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
        $o = [];
        $o['paid'] = $this->sale([['method' => 'cash', 'amount' => 10000]], 10000, 'Lunas');
        $o['pending'] = $this->sale([['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 15000]], 15000, 'Qris');
        $o['partial'] = $this->partial();
        $o['open'] = $this->tab();
        $voided = $this->sale([['method' => 'cash', 'amount' => 10000]], 10000, 'Batal');
        app(OrderService::class)->void($voided, 'salah', $this->owner);
        $o['cancelled'] = $voided->fresh();

        return $o;
    }

    public function test_every_order_is_in_exactly_one_tab_and_counts_equal_the_rows(): void
    {
        $o = $this->seedEveryStatus();

        $all = $this->listing();
        $this->assertSame(['all' => 5, 'open' => 1, 'partial' => 1, 'paid' => 2, 'cancelled' => 1], $all['counts']);
        $this->assertSame(5, $all['meta']['total']);

        foreach (['open', 'partial', 'paid', 'cancelled'] as $tab) {
            $rows = $this->listing("tab={$tab}");
            $this->assertSame($all['counts'][$tab], $rows['meta']['total'], $tab);
            foreach ($rows['data'] as $r) {
                $this->assertSame($tab, $r['tab']);
            }
        }

        $tabs = collect($all['data'])->pluck('tab', 'id');
        $this->assertSame('paid', $tabs[$o['paid']->id]);
        $this->assertSame('partial', $tabs[$o['partial']->id]);
        $this->assertSame('open', $tabs[$o['open']->id]);
        $this->assertSame('cancelled', $tabs[$o['cancelled']->id]);
    }

    public function test_counts_ignore_the_selected_tab_but_respect_every_other_filter(): void
    {
        $this->seedEveryStatus();

        $r = $this->listing('tab=open&type[]=dine_in');

        $this->assertSame(1, $r['meta']['total']);
        $this->assertSame(1, $r['counts']['all']);          // hanya dine_in
        $this->assertSame(1, $r['counts']['open']);
        $this->assertSame(0, $r['counts']['paid']);
    }

    public function test_the_all_tab_count_equals_the_dashboard_order_kpi(): void
    {
        $this->seedEveryStatus();

        $kpi = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('kpis.orders.value');

        $this->assertSame($kpi, $this->listing()['counts']['all']);
    }

    public function test_payment_state_shows_pending_for_a_fully_paid_unverified_transfer(): void
    {
        $o = $this->seedEveryStatus();
        $rows = collect($this->listing()['data'])->keyBy('id');

        $this->assertSame('pending', $rows[$o['pending']->id]['payment_state']);
        $this->assertSame('paid', $rows[$o['paid']->id]['payment_state']);
        $this->assertSame('partial', $rows[$o['partial']->id]['payment_state']);
        $this->assertSame('unpaid', $rows[$o['open']->id]['payment_state']);
        $this->assertSame(['qr_ewallet'], $rows[$o['pending']->id]['methods']);
        $this->assertSame('10000.00', $rows[$o['partial']->id]['paid_amount']);
        $this->assertSame('30000.00', $rows[$o['partial']->id]['total_amount']);
    }

    public function test_filters_by_payment_status_and_method(): void
    {
        $o = $this->seedEveryStatus();

        $pending = $this->listing('pstat[]=pending');
        $this->assertSame([$o['pending']->id], array_column($pending['data'], 'id'));

        $qris = $this->listing('method[]=qr_ewallet');
        $this->assertSame([$o['pending']->id], array_column($qris['data'], 'id'));

        $unpaidOrPartial = $this->listing('pstat[]=unpaid&pstat[]=partial');
        $this->assertEqualsCanonicalizing([$o['open']->id, $o['partial']->id], array_column($unpaidOrPartial['data'], 'id'));
    }

    public function test_search_matches_order_number_table_and_item_name(): void
    {
        $o = $this->seedEveryStatus();
        $tab = $o['open'];
        $tableName = $tab->table->name;

        $this->assertSame([$o['paid']->id], array_column($this->listing('q=Lunas')['data'], 'id'));
        $this->assertSame([$o['paid']->order_number], array_column($this->listing('q='.$o['paid']->order_number)['data'], 'order_number'));
        $this->assertContains($tab->id, array_column($this->listing('q='.$tableName)['data'], 'id'));
        $this->assertSame([], $this->listing('q=tidak-ada-sama-sekali')['data']);
    }

    public function test_the_date_range_uses_the_business_date_and_defaults_to_the_period(): void
    {
        $this->sale([['method' => 'cash', 'amount' => 10000]]);   // hari ini
        $old = $this->sale([['method' => 'cash', 'amount' => 10000]]);
        $old->forceFill(['business_date' => '2026-10-08', 'order_number' => 'TRX-OLD-1'])->save();

        $this->assertSame(1, $this->listing()['meta']['total']);
        $this->assertSame(2, $this->listing('from=2026-10-01&to=2026-10-11')['meta']['total']);
        $this->assertSame(1, $this->listing('from=2026-10-08&to=2026-10-08')['meta']['total']);
        $this->assertSame(2, $this->getJson('/api/v1/dashboard/orders?period=7d')->assertOk()->json('meta.total'));
    }

    public function test_pagination_and_sorting(): void
    {
        foreach ([30000, 10000, 20000] as $price) {
            $this->sale([['method' => 'cash', 'amount' => $price]], $price);
        }

        $page = $this->listing('sort=total_amount&per_page=2&page=1');
        $this->assertSame(['10000.00', '20000.00'], array_column($page['data'], 'total_amount'));
        $this->assertSame(2, $page['meta']['last_page']);
        $this->assertSame(['30000.00'], array_column($this->listing('sort=total_amount&per_page=2&page=2')['data'], 'total_amount'));
        $this->assertSame('30000.00', $this->listing('sort=-total_amount')['data'][0]['total_amount']);
    }

    public function test_hidden_tabs_and_no_contact_fields(): void
    {
        $this->seedEveryStatus();
        $r = $this->listing();

        $this->assertSame(['bill', 'closed'], $r['hidden_tabs']);
        foreach ($r['data'] as $row) {
            $this->assertArrayNotHasKey('customer', $row);
            $this->assertArrayNotHasKey('phone', $row);
            $this->assertArrayNotHasKey('email', $row);
        }
    }

    public function test_building_the_list_does_not_query_per_row(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->sale([['method' => 'cash', 'amount' => 10000]]);
        }
        $this->listing('per_page=50'); // pemanasan: peran pengguna dan lisensi dimuat sekali

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listing('per_page=50');
        $few = count(DB::getQueryLog());

        for ($i = 0; $i < 6; $i++) {
            $this->sale([['method' => 'cash', 'amount' => 10000]]);
        }
        DB::flushQueryLog();
        $this->listing('per_page=50');

        $this->assertSame($few, count(DB::getQueryLog()), 'jumlah query harus tetap saat baris bertambah');
    }

    public function test_demo_and_live_orders_never_mix(): void
    {
        $this->sale([['method' => 'cash', 'amount' => 10000]]);
        \App\Support\ModeGate::runAs(\App\Support\ModeGate::DEMO, function () {
            $this->assertSame(0, $this->listing()['meta']['total']);
        });
    }

    public function test_refused_without_the_dashboard_menu_and_rejects_bad_values(): void
    {
        $this->getJson('/api/v1/dashboard/orders?tab=nope')->assertStatus(422);
        $this->getJson('/api/v1/dashboard/orders?type[]=drive_thru')->assertStatus(422);

        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');
        $this->getJson('/api/v1/dashboard/orders')->assertForbidden();
    }
}
