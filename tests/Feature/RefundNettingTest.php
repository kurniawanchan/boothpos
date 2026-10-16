<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\User;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / S4 (keputusan pemilik) — refund mengurangi penjualan pada TANGGAL REFUND. Laporan hari-hari sebelumnya
 * tidak berubah; Sales page menandai baris refund; pendapatan laba-rugi ikut berkurang.
 */
class RefundNettingTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use OverrideFixtures;

    private User $cashier;

    private User $owner;

    private CashierSession $shift;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
        $this->variantId = $this->makeVariant(stock: 10, price: 8000)->id;
    }

    private function sale(): int
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variantId, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 8000]],
        ])->assertCreated()->json('id');
    }

    private function refund(int $orderId): void
    {
        $subject = ['order_id' => $orderId, 'reason_code' => 'wrong_item', 'reason' => 'salah pesan'];
        $token = $this->overrideToken('refund', $subject, (int) $this->shift->outlet_id);
        $this->postJson("/api/v1/orders/{$orderId}/refund", $subject + ['override_token' => $token])->assertOk();
    }

    private function sales(string $day)
    {
        return $this->getJson("/api/v1/reports/sales?date_from={$day}&date_to={$day}")->assertOk();
    }

    public function test_a_refund_today_lowers_todays_net_sales(): void
    {
        $today = BusinessDay::today();
        $order = $this->sale();
        $this->assertSame('8000.00', (string) $this->sales($today)->json('totals.net_sales'));

        $this->refund($order);

        $this->assertSame('0.00', (string) $this->sales($today)->json('totals.net_sales'));
        $this->assertSame('0.00', (string) $this->sales($today)->json('totals.gross_sales'));
    }

    public function test_a_refund_today_leaves_yesterdays_report_unchanged(): void
    {
        $today = BusinessDay::today();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $order = $this->sale();

        // Penjualan dipindah ke kemarin; refund terjadi hari ini.
        Order::withoutGlobalScopes()->whereKey($order)->update([
            'business_date' => $yesterday,
            'completed_at' => Carbon::parse($yesterday)->setTime(12, 0),
            'created_at' => Carbon::parse($yesterday)->setTime(12, 0),
        ]);
        $this->assertSame('8000.00', (string) $this->sales($yesterday)->json('totals.net_sales'));

        $this->refund($order);

        $this->assertSame('8000.00', (string) $this->sales($yesterday)->json('totals.net_sales'), 'laporan kemarin tidak berubah');
        $this->assertSame('-8000.00', (string) $this->sales($today)->json('totals.net_sales'), 'refund hari ini mengurangi hari ini');
    }

    public function test_the_sales_page_flags_the_refunded_row(): void
    {
        $today = BusinessDay::today();
        $order = $this->sale();
        $this->refund($order);

        $row = collect($this->sales($today)->json('transactions'))->firstWhere('id', $order);
        $this->assertNotNull($row);
        $this->assertTrue($row['refunded']);
        $this->assertSame($today, $row['refund_business_date']);
        $this->assertSame('completed', $row['status'], 'status order tetap completed; refund ditandai terpisah');
    }

    public function test_profit_revenue_is_reduced_by_the_refund(): void
    {
        $today = BusinessDay::today();
        $order = $this->sale();
        $this->refund($order);

        $this->actingAs($this->owner, 'sanctum');
        $this->getJson("/api/v1/reports/profit?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonPath('revenue', '0.00');
    }
}
