<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 048 / T027 (B8) — pajak eksklusif masuk ke total tab, pembayaran, dan snapshot order. */
class TabTaxTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private int $tab;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->sessionId = $this->makeShift($this->cashier)->id;
        $this->tab = $this->apiOpenTab($this->sessionId)->json('id');
    }

    private function setTax(string $rate): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => $rate, 'type' => 'string']);
    }

    public function test_default_tax_is_zero_and_total_equals_subtotal(): void
    {
        $v = $this->makeVariant(stock: 10, price: 20_000);

        $this->apiAddLine($this->tab, $v->id, 3)->assertCreated()
            ->assertJsonPath('subtotal', '60000.00')->assertJsonPath('tax_amount', '0.00')->assertJsonPath('total_amount', '60000.00');
    }

    public function test_ten_percent_tax_is_added_to_the_total_and_the_balance(): void
    {
        $this->setTax('10');
        $v = $this->makeVariant(stock: 10, price: 20_000);

        $this->apiAddLine($this->tab, $v->id, 3)->assertCreated()
            ->assertJsonPath('subtotal', '60000.00')
            ->assertJsonPath('tax_amount', '6000.00')->assertJsonPath('tax_rate', '10.00')
            ->assertJsonPath('total_amount', '66000.00')->assertJsonPath('balance', '66000.00');
    }

    public function test_paying_only_the_subtotal_leaves_the_tax_outstanding(): void
    {
        $this->setTax('10');
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $this->apiAddLine($this->tab, $v->id, 3);

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 60_000]])->assertOk()
            ->assertJsonPath('status', 'open')->assertJsonPath('balance', '6000.00');
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 6_000]])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('balance', '0.00');
    }

    public function test_order_discount_is_applied_before_tax(): void
    {
        $this->setTax('10');
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $this->apiAddLine($this->tab, $v->id, 3);

        // diskon 10.000 → dasar 50.000 → pajak 5.000 → total 55.000
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 55_000]], null, ['discount_amount' => 10_000])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('discount_amount', '10000.00')
            ->assertJsonPath('tax_amount', '5000.00')->assertJsonPath('total_amount', '55000.00');
    }

    public function test_the_tax_is_a_snapshot_a_later_rate_change_does_not_rewrite_a_paid_order(): void
    {
        $this->setTax('10');
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $this->apiAddLine($this->tab, $v->id, 3);
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 66_000]])->assertJsonPath('status', 'completed');

        $this->setTax('25');

        $this->getJson("/api/v1/tabs/{$this->tab}")->assertJsonPath('tax_amount', '6000.00')->assertJsonPath('tax_rate', '10.00')->assertJsonPath('total_amount', '66000.00');
    }

    public function test_tax_is_kept_when_a_line_is_removed_and_recalculated(): void
    {
        $this->setTax('10');
        $a = $this->makeVariant(stock: 10, price: 20_000);
        $b = $this->makeVariant(stock: 10, price: 10_000);
        $this->apiAddLine($this->tab, $a->id, 1);
        $lineB = $this->apiAddLine($this->tab, $b->id, 1)->json('lines.1.id');

        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$lineB}")->assertOk()
            ->assertJsonPath('subtotal', '20000.00')->assertJsonPath('tax_amount', '2000.00')->assertJsonPath('total_amount', '22000.00');
    }
}
