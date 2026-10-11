<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ModeGate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T017) — GET /dashboard/summary.
 *
 * Jam beku: 2026-10-11 05:30 UTC = 12.30 WIB. Hari bisnis ini mulai 2026-10-10 17:00 UTC;
 * kemarin 2026-10-09 17:00 UTC .. 2026-10-10 17:00 UTC.
 */
class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    private CashierSession $session;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 05:30:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->session = $this->makeShift($this->owner);
        $this->variant = $this->makeVariant(stock: 1000, price: 10000);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Buat order selesai pada instan UTC tertentu bernilai `$qty × 10.000`. */
    private function saleAt(string $utc, int $qty = 1): \App\Models\Order
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($utc);
        try {
            return app(OrderService::class)->create([
                'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
                'payments' => [['method' => 'cash', 'amount' => 10000 * $qty]],
            ], $this->owner);
        } finally {
            CarbonImmutable::setTestNow($previous);
        }
    }

    private function summary(string $period = 'today'): array
    {
        return $this->getJson("/api/v1/dashboard/summary?period={$period}")->assertOk()->json();
    }

    public function test_today_compares_with_yesterday_up_to_the_same_time_of_day(): void
    {
        $this->saleAt('2026-10-10 02:00:00', 10);   // kemarin 09.00 WIB — sebelum jam yang sama
        $this->saleAt('2026-10-10 09:00:00', 5);    // kemarin 16.00 WIB — SESUDAH jam yang sama, tidak ikut pembanding
        $this->saleAt('2026-10-11 03:00:00', 20);   // hari ini 10.00 WIB
        $this->saleAt('2026-10-11 04:00:00', 5);    // hari ini 11.00 WIB

        $s = $this->summary();

        $this->assertSame('today', $s['period']);
        $this->assertSame('same_time_yesterday', $s['comparison']['basis']);
        $this->assertSame('12:30', $s['comparison']['up_to']);
        $this->assertSame('250000.00', $s['kpis']['net_sales']['value']);
        $this->assertSame('100000.00', $s['kpis']['net_sales']['comparison']);
        $this->assertEquals(150.0, $s['kpis']['net_sales']['change_pct']);
        $this->assertSame(2, $s['kpis']['orders']['value']);
        $this->assertSame(1, $s['kpis']['orders']['comparison']);
        $this->assertSame('125000.00', $s['kpis']['avg_order_value']['value']);
        $this->assertSame('2026-10-11', $s['business_date']);
        $this->assertSame('2026-10-11T12:30:00+07:00', $s['computed_at']);
    }

    public function test_the_headline_sales_equal_the_sales_page_for_the_same_business_date(): void
    {
        $this->saleAt('2026-10-11 03:00:00', 3);
        $this->saleAt('2026-10-10 18:00:00', 2); // 01.00 WIB tanggal 11 — hari bisnis hari ini
        $this->saleAt('2026-10-10 16:00:00', 7); // 23.00 WIB tanggal 10 — kemarin

        $report = $this->getJson('/api/v1/reports/sales?group_by=day&date_from=2026-10-11&date_to=2026-10-11')->assertOk()->json('totals');
        $s = $this->summary();

        $this->assertSame($report['pos_net_sales'], $s['kpis']['net_sales']['value']);
        $this->assertSame('50000.00', $s['kpis']['net_sales']['value']);
    }

    public function test_a_voided_order_is_excluded_from_sales_and_average_but_counts_as_an_order(): void
    {
        $this->saleAt('2026-10-11 03:00:00', 4);
        $voided = $this->saleAt('2026-10-11 03:30:00', 6);
        app(OrderService::class)->void($voided, 'salah input', $this->owner);

        $s = $this->summary();

        $this->assertSame('40000.00', $s['kpis']['net_sales']['value']);
        $this->assertSame(2, $s['kpis']['orders']['value']);            // semua status
        $this->assertSame('40000.00', $s['kpis']['avg_order_value']['value']); // bersih ÷ order SELESAI (1)
    }

    public function test_an_empty_day_shows_zeros_and_no_comparison_never_nan(): void
    {
        $s = $this->summary();

        $this->assertSame('0.00', $s['kpis']['net_sales']['value']);
        $this->assertNull($s['kpis']['net_sales']['change_pct']);
        $this->assertSame(0, $s['kpis']['orders']['value']);
        $this->assertNull($s['kpis']['orders']['change_pct']);
        $this->assertNull($s['kpis']['avg_order_value']['value']);
        $this->assertNull($s['kpis']['avg_order_value']['change_pct']);
    }

    public function test_hourly_series_is_cumulative_and_the_comparison_covers_the_whole_day(): void
    {
        $this->saleAt('2026-10-10 17:30:00', 1);  // 00.30 WIB
        $this->saleAt('2026-10-11 03:10:00', 2);  // 10.10 WIB

        $s = $this->summary();
        $series = $s['kpis']['net_sales']['series'];

        $this->assertSame('00:00', $series[0]['t']);
        $this->assertSame('10000.00', $series[0]['v']);
        $this->assertSame('10000.00', $series[9]['v']);
        $this->assertSame('30000.00', $series[10]['v']);
        $this->assertCount(13, $series); // jam 00..12 (jam 12.00 sedang berjalan)
        $this->assertCount(24, $s['kpis']['net_sales']['comparison_series']);
    }

    #[DataProvider('ranges')]
    public function test_seven_and_thirty_day_periods_use_the_previous_period_and_daily_points(string $period, int $days): void
    {
        $this->saleAt('2026-10-11 03:00:00', 2);
        $this->saleAt('2026-10-04 03:00:00', 1);          // di luar rentang 7 hari (5–11 Okt); masuk rentang 30 hari
        $this->saleAt('2026-09-20 03:00:00', 1);

        $s = $this->summary($period);

        $this->assertSame('previous_period', $s['comparison']['basis']);
        $this->assertCount($days, $s['kpis']['net_sales']['series']);
        $this->assertSame('2026-10-11', $s['range']['to']);
        $series = $s['kpis']['net_sales']['series'];
        $this->assertSame('20000.00', end($series)['v']);
    }

    public static function ranges(): array
    {
        return ['7 hari' => ['7d', 7], '30 hari' => ['30d', 30]];
    }

    public function test_the_seven_day_comparison_is_the_equal_preceding_period(): void
    {
        $this->saleAt('2026-10-11 03:00:00', 2);  // dalam 5–11 Okt
        $this->saleAt('2026-10-04 03:00:00', 3);  // dalam pembanding 28 Sep–4 Okt

        $s = $this->summary('7d');

        $this->assertSame('2026-10-05', $s['range']['from']);
        $this->assertSame('2026-09-28', $s['comparison']['from']);
        $this->assertSame('2026-10-04', $s['comparison']['to']);
        $this->assertSame('20000.00', $s['kpis']['net_sales']['value']);
        $this->assertSame('30000.00', $s['kpis']['net_sales']['comparison']);
        $this->assertSame(-33.3, $s['kpis']['net_sales']['change_pct']);
    }

    public function test_low_stock_reports_the_count_the_change_and_the_organisation_scope(): void
    {
        $low = $this->makeVariant(stock: 2, price: 5000, variantOverrides: ['low_stock_alert' => 5]);
        $this->makeVariant(stock: 50, price: 5000, variantOverrides: ['low_stock_alert' => 5]);

        $s = $this->summary();

        $this->assertSame(1, $s['kpis']['low_stock']['value']);
        $this->assertSame('organisation', $s['kpis']['low_stock']['scope']);
        $this->assertArrayHasKey('change_abs', $s['kpis']['low_stock']);
        $this->assertSame($low->id, \App\Models\ProductVariant::where('low_stock_alert', 5)->whereColumn('current_stock', '<=', 'low_stock_alert')->value('id'));
    }

    public function test_demo_and_live_data_never_mix(): void
    {
        $this->saleAt('2026-10-11 03:00:00', 2);

        ModeGate::runAs(ModeGate::DEMO, function () {
            $demoOwner = User::factory()->create(['role' => 'owner']);
            $session = $this->makeShift($demoOwner);
            $variant = $this->makeVariant(stock: 100, price: 10000);
            app(OrderService::class)->create([
                'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $variant->id, 'qty' => 50]],
                'payments' => [['method' => 'cash', 'amount' => 500000]],
            ], $demoOwner);
        });

        $this->assertSame('20000.00', $this->summary()['kpis']['net_sales']['value']);
    }

    public function test_the_cache_is_keyed_by_mode_so_a_mode_switch_never_serves_the_other_modes_numbers(): void
    {
        $this->saleAt('2026-10-11 03:00:00', 2);
        $this->assertSame('20000.00', $this->summary()['kpis']['net_sales']['value']);

        ModeGate::runAs(ModeGate::DEMO, fn () => $this->assertSame('0.00', $this->summary()['kpis']['net_sales']['value']));
    }

    public function test_a_role_without_the_dashboard_menu_is_refused(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    public function test_an_invalid_period_is_a_validation_error(): void
    {
        $this->getJson('/api/v1/dashboard/summary?period=90d')->assertStatus(422);
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
    }
}
