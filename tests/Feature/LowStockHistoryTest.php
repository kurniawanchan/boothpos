<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LowStockHistory;
use App\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T018) — stok menipis sekarang dan di instan lampau, dari buku besar stock_movements.
 */
class LowStockHistoryTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function move($variant, string $at, string $type, int $qty): void
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($at);
        try {
            app(StockService::class)->applyMovement($variant, \App\Support\OutletStockSync::homeOutletOf($variant), $type, $qty);
        } finally {
            CarbonImmutable::setTestNow($previous);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_current_count_equals_the_stock_low_endpoint(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->makeVariant(stock: 2, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->makeVariant(stock: 9, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->makeVariant(stock: 1, price: 1000);                                        // tanpa ambang: diabaikan
        $this->makeVariant(stock: 1, price: 1000, variantOverrides: ['low_stock_alert' => 5, 'is_active' => false]); // nonaktif

        $this->assertSame(1, LowStockHistory::current());
        $this->assertCount(1, $this->getJson('/api/v1/stock/low')->assertOk()->json('data'));
    }

    public function test_past_levels_are_replayed_from_the_ledger(): void
    {
        $v = $this->makeVariant(stock: 0, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->move($v, '2026-10-08 01:00:00', 'initial', 10);      // 10 → tidak menipis
        $this->move($v, '2026-10-10 03:00:00', 'sale', -7);         // 3 → menipis
        $this->move($v, '2026-10-11 02:00:00', 'purchase', 10);     // 13 → aman lagi

        [$beforeSale, $afterSale, $afterPurchase] = LowStockHistory::at([
            CarbonImmutable::parse('2026-10-09 12:00:00'),
            CarbonImmutable::parse('2026-10-10 05:30:00'),
            CarbonImmutable::parse('2026-10-11 05:30:00'),
        ]);

        $this->assertSame([0, 1, 0], [$beforeSale, $afterSale, $afterPurchase]);
    }

    public function test_input_order_is_preserved_and_a_variant_with_no_movement_yet_is_not_counted(): void
    {
        $v = $this->makeVariant(stock: 0, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->move($v, '2026-10-10 03:00:00', 'initial', 2);       // menipis sejak dibuat

        $result = LowStockHistory::at([
            CarbonImmutable::parse('2026-10-11 00:00:00'),
            CarbonImmutable::parse('2026-10-09 00:00:00'),          // sebelum varian ada
        ]);

        $this->assertSame([1, 0], $result);
    }

    public function test_the_summary_reports_the_change_against_yesterday_at_the_same_time(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $v = $this->makeVariant(stock: 0, price: 1000, variantOverrides: ['low_stock_alert' => 5]);
        $this->move($v, '2026-10-08 01:00:00', 'initial', 10);
        $this->move($v, '2026-10-10 03:00:00', 'sale', -7);         // menipis kemarin 10.00 WIB
        $this->move($v, '2026-10-11 02:00:00', 'purchase', 10);     // aman hari ini 09.00 WIB

        CarbonImmutable::setTestNow('2026-10-11 05:30:00');         // 12.30 WIB
        $low = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('kpis.low_stock');

        $this->assertSame(0, $low['value']);
        $this->assertSame(1, $low['comparison']);
        $this->assertSame(-1, $low['change_abs']);
        $this->assertSame(24, count($low['comparison_series']));
    }
}
