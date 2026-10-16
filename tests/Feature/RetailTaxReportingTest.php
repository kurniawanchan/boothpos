<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 (keputusan pemilik) — penjualan retail (POST /orders) kena pajak eksklusif yang sama dengan tab. Laporan
 * menampilkan net, pajak, dan grand total (= net + pajak, angka yang sama dengan struk). Refund membalik pajak
 * dan biaya barang pada TANGGAL REFUND, sama seperti pendapatan.
 */
class RetailTaxReportingTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private User $owner;

    private CashierSession $shift;

    private int $variantId;

    private int $otherVariantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
        // Harga 20.000 × 3 = 60.000 (harga pokok 12.000 per unit, jadi biaya 36.000).
        $this->variantId = $this->makeVariant(stock: 10, price: 20000, variantOverrides: ['cost_price' => 12000])->id;
        $this->otherVariantId = $this->makeVariant(stock: 10, price: 10000, variantOverrides: ['cost_price' => 4000])->id;
    }

    private function setTax(string $rate): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => $rate, 'type' => 'string']);
    }

    /** @param  list<array{variant_id: int, qty: int}>  $items */
    private function sale(array $items, int $cash, int $discount = 0): array
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
            'discount_amount' => $discount,
            'items' => $items,
            'payments' => [['method' => 'cash', 'amount' => $cash]],
        ])->assertCreated()->json();
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

    public function test_a_retail_sale_charges_ten_percent_tax_on_the_order_and_the_receipt_shows_it(): void
    {
        $this->setTax('10');

        $sale = $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 66000);

        $this->assertSame('60000.00', $sale['subtotal']);
        $this->assertSame('6000.00', $sale['tax_amount']);
        $this->assertSame('10.00', $sale['tax_rate']);
        $this->assertSame('66000.00', $sale['total_amount']);

        $this->getJson("/api/v1/orders/{$sale['id']}/receipt")->assertOk()
            ->assertJsonPath('tax_amount', '6000.00')
            ->assertJsonPath('tax_rate', '10.00')
            ->assertJsonPath('total_amount', '66000.00');
    }

    public function test_the_sales_report_shows_net_tax_and_grand_total_side_by_side(): void
    {
        $this->setTax('10');
        $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 66000);

        $totals = $this->sales(BusinessDay::today())->json('totals');

        $this->assertSame('60000.00', $totals['net_sales'], 'net tetap pra-pajak');
        $this->assertSame('6000.00', $totals['tax_total']);
        $this->assertSame('66000.00', $totals['grand_total'], 'grand total = net + pajak, sama dengan struk');
        $this->assertSame('0.00', $totals['order_discount_total']);
    }

    public function test_tax_is_counted_once_per_order_not_once_per_item_row(): void
    {
        $this->setTax('10');
        // Dua baris item (60.000 + 10.000 = 70.000) → pajak 7.000 sekali, bukan 14.000.
        $this->sale([
            ['variant_id' => $this->variantId, 'qty' => 3],
            ['variant_id' => $this->otherVariantId, 'qty' => 1],
        ], 77000);

        $totals = $this->sales(BusinessDay::today())->json('totals');

        $this->assertSame('70000.00', $totals['net_sales']);
        $this->assertSame('7000.00', $totals['tax_total']);
        $this->assertSame('77000.00', $totals['grand_total']);
    }

    public function test_an_order_level_discount_is_taxed_on_the_discounted_base_and_grand_total_equals_the_receipt(): void
    {
        $this->setTax('10');

        // 60.000 − diskon tingkat-order 10.000 = 50.000; pajak 10% dari 50.000 = 5.000; total 55.000.
        $sale = $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 55000, discount: 10000);

        $this->assertSame('5000.00', $sale['tax_amount']);
        $this->assertSame('55000.00', $sale['total_amount']);

        $this->getJson("/api/v1/orders/{$sale['id']}/receipt")->assertOk()->assertJsonPath('total_amount', '55000.00');

        // Keputusan O3 (dikunci SalesTotalsCharacterizationTest): net tetap 60.000; diskon tampil sebagai baris sendiri.
        $totals = $this->sales(BusinessDay::today())->json('totals');
        $this->assertSame('60000.00', $totals['net_sales']);
        $this->assertSame('10000.00', $totals['order_discount_total']);
        $this->assertSame('5000.00', $totals['tax_total']);
        $this->assertSame('55000.00', $totals['grand_total'], 'grand total = net − diskon tingkat-order + pajak = total struk');
    }

    public function test_a_refund_of_a_discounted_order_nets_out_its_grand_total_on_the_refund_date(): void
    {
        $this->setTax('10');
        $sale = $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 55000, discount: 10000);
        $this->assertSame('55000.00', $this->sales(BusinessDay::today())->json('totals.grand_total'));

        $this->refund($sale['id']);

        $totals = $this->sales(BusinessDay::today())->json('totals');
        $this->assertSame('0.00', $totals['net_sales'], 'refund 60.000 mengurangi net');
        $this->assertSame('0.00', $totals['order_discount_total'], 'refund 10.000 mengurangi diskon tingkat-order');
        $this->assertSame('0.00', $totals['tax_total'], 'refund 5.000 mengurangi pajak');
        $this->assertSame('0.00', $totals['grand_total'], 'refund mengurangi grand total 55.000 (net − diskon + pajak)');
    }

    public function test_the_dashboard_revenue_block_shows_the_order_discount_and_grand_total_for_a_discounted_order(): void
    {
        $this->setTax('10');
        $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 55000, discount: 10000);

        $this->actingAs($this->owner, 'sanctum');
        foreach (['today', '7d'] as $period) {
            $this->getJson("/api/v1/dashboard/summary?period={$period}")->assertOk()
                ->assertJsonPath('revenue.net_sales', '60000.00')
                ->assertJsonPath('revenue.order_discount_total', '10000.00')
                ->assertJsonPath('revenue.tax_total', '5000.00')
                ->assertJsonPath('revenue.grand_total', '55000.00');
        }
    }

    public function test_a_refund_takes_its_tax_out_of_tax_total_and_grand_total_on_the_refund_day(): void
    {
        $this->setTax('10');
        $sale = $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 66000);

        $this->refund($sale['id']);

        $totals = $this->sales(BusinessDay::today())->json('totals');
        $this->assertSame('0.00', $totals['net_sales']);
        $this->assertSame('0.00', $totals['tax_total']);
        $this->assertSame('0.00', $totals['grand_total']);
    }

    public function test_a_refund_reverses_the_cost_of_the_goods_in_profit(): void
    {
        $sale = $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 60000);
        $today = BusinessDay::today();

        $this->actingAs($this->owner, 'sanctum');
        $this->getJson("/api/v1/reports/profit?date_from={$today}&date_to={$today}")->assertOk()
            ->assertJsonPath('revenue', '60000.00')
            ->assertJsonPath('cost_of_goods', '36000.00')
            ->assertJsonPath('gross_profit', '24000.00');

        $this->actingAs($this->cashier, 'sanctum');
        $this->refund($sale['id']);

        $this->actingAs($this->owner, 'sanctum');
        $this->getJson("/api/v1/reports/profit?date_from={$today}&date_to={$today}")->assertOk()
            ->assertJsonPath('revenue', '0.00')
            ->assertJsonPath('cost_of_goods', '0.00')
            ->assertJsonPath('gross_profit', '0.00');
    }

    public function test_the_dashboard_revenue_block_carries_net_tax_and_grand_total_for_today_and_7d(): void
    {
        $this->setTax('10');
        $this->sale([['variant_id' => $this->variantId, 'qty' => 3]], 66000);

        $this->actingAs($this->owner, 'sanctum');
        foreach (['today', '7d'] as $period) {
            $this->getJson("/api/v1/dashboard/summary?period={$period}")->assertOk()
                ->assertJsonPath('revenue.net_sales', '60000.00')
                ->assertJsonPath('revenue.tax_total', '6000.00')
                ->assertJsonPath('revenue.grand_total', '66000.00');
        }
    }
}
