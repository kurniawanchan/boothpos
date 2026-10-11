<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T037, T043) — ekspor CSV tabel dan ekspor Dashboard.
 */
class DashboardOrdersExportTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 05:30:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function sale(string $name, float $price): Order
    {
        $session = \App\Models\CashierSession::first() ?? $this->makeShift($this->owner);
        $variant = $this->makeVariant(stock: 50, price: $price, variantOverrides: ['variant_name' => $name]);

        return app(OrderService::class)->create([
            'session_id' => $session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => \App\Models\Customer::factory()->create(['phone' => '081234567890', 'email' => 'rahasia@example.test'])->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => $price]],
        ], $this->owner);
    }

    /** @return array<int,array<string,string>> */
    private function csv(string $url): array
    {
        $res = $this->get($url)->assertOk();
        $this->assertStringStartsWith('text/csv', $res->headers->get('Content-Type'));
        $content = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = array_filter(preg_split('/\r?\n/', substr($content, 3)));
        $rows = array_map('str_getcsv', $lines);

        return $rows;
    }

    public function test_the_csv_rows_equal_the_filtered_screen_rows_and_carry_no_contact_data(): void
    {
        $this->sale('Kopi', 10000);
        $this->sale('Teh', 8000);
        $this->sale('Roti', 12000);

        $screen = $this->getJson('/api/v1/dashboard/orders?q=Teh')->assertOk()->json('data');
        $rows = $this->csv('/api/v1/dashboard/orders/export?q=Teh');

        $this->assertSame(['order_number', 'at', 'type', 'table', 'items', 'status', 'payment_state', 'methods', 'total_amount', 'paid_amount'], $rows[0]);
        $this->assertCount(count($screen) + 1, $rows);
        $this->assertSame($screen[0]['order_number'], $rows[1][0]);
        $this->assertSame($screen[0]['total_amount'], $rows[1][8]);

        $raw = $this->get('/api/v1/dashboard/orders/export')->streamedContent();
        $this->assertStringNotContainsString('081234567890', $raw);
        $this->assertStringNotContainsString('rahasia@example.test', $raw);
    }

    public function test_the_unfiltered_csv_has_every_row_of_the_period_not_just_one_page(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->sale('Item '.$i, 5000);
        }

        $rows = $this->csv('/api/v1/dashboard/orders/export?per_page=1');

        $this->assertCount(3 + 1, $rows);
    }

    public function test_the_dashboard_export_matches_the_summary_and_attention(): void
    {
        $this->sale('Kopi', 10000);
        $summary = $this->getJson('/api/v1/dashboard/summary?period=today')->assertOk()->json();
        $attention = $this->getJson('/api/v1/dashboard/attention')->assertOk()->json();

        $rows = $this->csv('/api/v1/dashboard/export?period=today');
        $flat = collect($rows)->keyBy(fn ($r) => $r[0] ?? '');

        $this->assertSame('computed_at', $rows[0][0]);
        $this->assertSame($summary['computed_at'], $rows[0][1]);
        $this->assertSame('today', $flat['period'][1]);
        $this->assertSame($summary['kpis']['net_sales']['value'], $flat['net_sales'][1]);
        $this->assertSame((string) $summary['kpis']['orders']['value'], $flat['orders'][1]);
        $this->assertSame((string) $attention['items'][0]['count'], $flat['payments_pending'][2]);
        $this->assertArrayHasKey('trend:net_sales', $flat->all());
        $this->assertArrayHasKey('low_stock', $flat->all());
    }

    public function test_exports_require_the_dashboard_menu(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->get('/api/v1/dashboard/orders/export')->assertForbidden();
        $this->get('/api/v1/dashboard/export')->assertForbidden();
    }
}
