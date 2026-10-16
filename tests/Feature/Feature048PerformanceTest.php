<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ModeGate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / T070 — audit performa endpoint baru: jumlah query TIDAK tumbuh dengan jumlah baris
 * (Constitution V, pola TabPerformanceTest), dan cache dasbor dikunci mode data (tidak bocor DEMO↔LIVE).
 */
class Feature048PerformanceTest extends TestCase
{
    use RefreshDatabase;
    use OverrideFixtures;
    use RestaurantFixtures;

    private User $cashier;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::first() ?? Outlet::factory()->create();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function queryCount(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** Satu penjualan tunai lengkap lewat API (tab → baris → bayar). */
    private function sell(CashierSession $session, ProductVariant $variant): void
    {
        $tab = $this->apiOpenTab($session->id)->json('id');
        $total = $this->apiAddLine($tab, $variant->id, 1)->assertCreated()->json('total_amount');
        $this->apiPay($tab, [['method' => 'cash', 'amount' => (float) $total]])->assertOk();
    }

    public function test_floor_query_count_does_not_grow_with_open_tabs(): void
    {
        $session = $this->makeShift($this->cashier);
        $variant = $this->makeVariant(stock: 100000, price: 1000);
        $this->openTables($session, $variant, 3);
        $this->getJson('/api/v1/floor')->assertOk(); // pemanasan

        $small = $this->queryCount(fn () => $this->getJson('/api/v1/floor')->assertOk());
        $this->openTables($session, $variant, 6);
        $large = $this->queryCount(fn () => $this->getJson('/api/v1/floor')->assertOk());

        $this->assertSame($small, $large, "GET /floor: 3 tab = $small query, 9 tab = $large query (N+1)");
    }

    private function openTables(CashierSession $session, ProductVariant $variant, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $table = DiningTable::factory()->create();
            $tab = $this->makeOpenOrder($session, $this->cashier, ['table_id' => $table->id, 'service_type' => 'dine_in']);
            $this->addLineTo($tab, $variant, 2);
            $this->addLineTo($tab, $variant, 1);
        }
    }

    public function test_reading_a_tab_query_count_does_not_grow_with_lines_modifiers_or_payments(): void
    {
        $session = $this->makeShift($this->cashier);
        $variant = $this->makeVariant(stock: 100000, price: 1000);

        $small = $this->makeOpenOrder($session, $this->cashier, ['table_id' => DiningTable::factory()->create()->id, 'service_type' => 'dine_in']);
        $this->addLineTo($small, $variant, 2);
        $this->addCashPaymentTo($small, 500);

        $large = $this->makeOpenOrder($session, $this->cashier, ['table_id' => DiningTable::factory()->create()->id, 'service_type' => 'dine_in']);
        for ($i = 0; $i < 20; $i++) {
            $this->addLineTo($large, $variant, 1);
        }
        // Modifier disimpan sebagai JSON pada baris; set langsung supaya tes tidak bergantung pada alur order.
        DB::table('order_items')->where('order_id', $large->id)->update(['modifiers' => json_encode([['group' => 'gula', 'option' => 'normal']])]);
        for ($i = 0; $i < 9; $i++) {
            $this->addCashPaymentTo($large, 500);
        }

        $this->getJson("/api/v1/tabs/{$small->id}")->assertOk(); // pemanasan
        $a = $this->queryCount(fn () => $this->getJson("/api/v1/tabs/{$small->id}")->assertOk());
        $b = $this->queryCount(fn () => $this->getJson("/api/v1/tabs/{$large->id}")->assertOk());

        $this->assertSame($a, $b, "GET /tabs/{id}: 2 baris+1 bayar = $a query, 20 baris+9 bayar = $b query (N+1)");
    }

    public function test_sales_report_query_count_does_not_grow_with_orders(): void
    {
        $session = $this->makeShift($this->cashier);
        $variant = $this->makeVariant(stock: 100000, price: 10000);
        for ($i = 0; $i < 3; $i++) {
            $this->sell($session, $variant);
        }

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $day = \App\Support\BusinessDay::today();
        $url = "/api/v1/reports/sales?date_from={$day}&date_to={$day}&group_by=day";
        $this->getJson($url)->assertOk(); // pemanasan

        $small = $this->queryCount(fn () => $this->getJson($url)->assertOk());

        $this->actingAs($this->cashier, 'sanctum');
        for ($i = 0; $i < 6; $i++) {
            $this->sell($session, $variant);
        }
        $this->actingAs($owner, 'sanctum');
        $large = $this->queryCount(fn () => $this->getJson($url)->assertOk());

        $this->assertSame($small, $large, "GET /reports/sales: 3 order = $small query, 9 order = $large query (N+1)");
    }

    public function test_heartbeat_query_count_is_constant_with_other_devices_registered(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $device = $this->postJson('/api/v1/devices/pairings', ['name' => 'Tablet kasir'])->assertCreated()->json('id');
        $body = ['app_version' => '1.0.0', 'clock_ms' => now()->getTimestampMs(), 'queue_depth' => 0];

        $this->actingAs($this->cashier, 'sanctum');
        $this->postJson('/api/v1/devices/heartbeat', $body, ['X-Device-Id' => (string) $device])->assertOk(); // pemanasan
        // Eloquent membandingkan last_seen_at pada presisi detik: waktu yang berbeda menjamin UPDATE di setiap pengukuran.
        Carbon::setTestNow(now()->addSeconds(10));
        $small = $this->queryCount(fn () => $this->postJson('/api/v1/devices/heartbeat', $body, ['X-Device-Id' => (string) $device])->assertOk());

        $this->actingAs($owner, 'sanctum');
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/devices/pairings', ['name' => 'Perangkat '.Str::random(4)])->assertCreated();
        }
        $this->actingAs($this->cashier, 'sanctum');
        Carbon::setTestNow(now()->addSeconds(20));
        $large = $this->queryCount(fn () => $this->postJson('/api/v1/devices/heartbeat', $body, ['X-Device-Id' => (string) $device])->assertOk());

        $this->assertSame($small, $large, "POST /devices/heartbeat: 1 perangkat = $small query, 7 perangkat = $large query");
    }

    public function test_approver_list_query_count_does_not_grow_with_staff(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->approver($this->outlet->id, 'owner');
        }
        $url = '/api/v1/overrides/approvers?action=discount';
        $headers = ['X-Outlet-Id' => (string) $this->outlet->id];
        $this->getJson($url, $headers)->assertOk(); // pemanasan

        $small = $this->queryCount(fn () => $this->getJson($url, $headers)->assertOk());
        for ($i = 0; $i < 6; $i++) {
            $this->approver($this->outlet->id, 'owner');
        }
        $large = $this->queryCount(fn () => $this->getJson($url, $headers)->assertOk());

        $this->assertSame($small, $large, "GET /overrides/approvers: 3 penyetuju = $small query, 9 penyetuju = $large query");
    }

    public function test_dashboard_summary_is_cached_per_data_mode(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        // Penjualan DEMO saja. Ringkasan DEMO mencatatnya; ringkasan LIVE (cache terpisah) tidak boleh melihatnya.
        ModeGate::runAs(ModeGate::DEMO, function () {
            $this->actingAs($this->cashier, 'sanctum');
            $session = $this->makeShift($this->cashier);
            $this->sell($session, $this->makeVariant(stock: 100, price: 10000));
            $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
            $this->getJson('/api/v1/dashboard/summary?period=today')->assertOk()
                ->assertJsonPath('kpis.net_sales.value', '10000.00');
        });

        ModeGate::runAs('live', function () {
            $this->getJson('/api/v1/dashboard/summary?period=today')->assertOk()
                ->assertJsonPath('kpis.net_sales.value', '0.00');
        });
    }
}
