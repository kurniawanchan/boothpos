<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderService;
use App\Services\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T013, T014) — laporan penjualan dan daftar transaksi memakai TANGGAL BISNIS
 * (zona waktu toko), bukan DATE(created_at) UTC; SalesFigures adalah sumber angkanya.
 */
class ReportsBusinessDateTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    private $session;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->session = $this->makeShift($this->owner);
        $this->variant = $this->makeVariant(stock: 500, price: 10000);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function saleAt(string $utc, int $qty = 1): void
    {
        CarbonImmutable::setTestNow($utc);
        app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => 10000 * $qty]],
        ], $this->owner);
    }

    public function test_a_sale_after_midnight_wib_is_reported_on_the_local_day_in_the_report_and_the_list(): void
    {
        $this->saleAt('2026-10-10 19:00:00', 2); // 02.00 WIB tanggal 11 (tanggal UTC: 10)
        CarbonImmutable::setTestNow('2026-10-11 05:00:00');

        $on11 = $this->getJson('/api/v1/reports/sales?group_by=day&date_from=2026-10-11&date_to=2026-10-11')->assertOk()->json();
        $on10 = $this->getJson('/api/v1/reports/sales?group_by=day&date_from=2026-10-10&date_to=2026-10-10')->assertOk()->json();

        $this->assertSame('20000.00', $on11['totals']['pos_net_sales']);
        $this->assertCount(1, $on11['transactions']);
        $this->assertSame('0.00', number_format((float) ($on10['totals']['pos_net_sales'] ?? 0), 2, '.', ''));
        $this->assertCount(0, $on10['transactions']);
        $this->assertSame('2026-10-11', $on11['rows'][0]['label']);
    }

    public function test_sales_figures_group_by_business_date_and_count_every_status(): void
    {
        $this->saleAt('2026-10-10 03:00:00');            // 10 Okt
        $this->saleAt('2026-10-10 19:00:00', 3);         // 11 Okt (02.00 WIB)
        $this->saleAt('2026-10-11 03:00:00');            // 11 Okt

        $net = SalesFigures::netByDay('2026-10-10', '2026-10-11');
        $count = SalesFigures::orderCountByDay('2026-10-10', '2026-10-11');

        $this->assertSame(['2026-10-10' => '10000.00', '2026-10-11' => '40000.00'], $net);
        $this->assertSame(['2026-10-10' => 1, '2026-10-11' => 2], $count);
    }

    public function test_the_hourly_window_is_half_open(): void
    {
        $this->saleAt('2026-10-10 03:00:00');

        $start = CarbonImmutable::parse('2026-10-10 03:00:00');
        $this->assertCount(1, SalesFigures::completedNetBetween($start, $start->addSecond()));
        $this->assertCount(0, SalesFigures::completedNetBetween($start->subHour(), $start));   // batas atas eksklusif
        $this->assertCount(1, SalesFigures::orderInstantsBetween($start, $start->addHour()));
    }

    public function test_the_totals_helper_returns_zeros_for_an_empty_base(): void
    {
        $t = SalesFigures::totals(SalesFigures::posBase(['date_from' => '2030-01-01', 'date_to' => '2030-01-01']));

        $this->assertSame(0, (int) $t->order_count);
        $this->assertSame(0.0, (float) $t->net_sales);
    }
}
