<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 — tunai dari tab (meja/counter) harus masuk kas shift yang sama dengan penjualan POS: payments.session_id
 * terisi, X-report dan kas seharusnya menghitungnya, dan jam penjualan dikirim sebagai string sesuai kontrak.
 */
class ShiftTabCashTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    public function test_tab_cash_is_attributed_to_the_shift_and_counted_in_the_x_report(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = $this->makeShift($cashier, ['opening_cash' => 150000]);
        $tab = $this->apiOpenTab($shift->id)->json('id');
        $variant = $this->makeVariant(stock: 10, price: 8000);
        $this->apiAddLine($tab, $variant->id, 1)->assertCreated();

        $this->apiPay($tab, [['method' => 'cash', 'amount' => 10000]])->assertOk();

        $payment = Payment::where('order_id', $tab)->firstOrFail();
        $this->assertSame($shift->id, $payment->session_id, 'tunai tab harus tercatat di shift yang membukanya');

        $this->getJson("/api/v1/sessions/{$shift->id}/x-report")
            ->assertOk()
            ->assertJsonPath('cash_received', '10000.00')
            ->assertJsonPath('change_given', '2000.00')
            ->assertJsonPath('hourly.0.order_count', 1);

        // Kas seharusnya = modal 150.000 + tunai diterima 10.000 − kembalian 2.000 = 158.000.
        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => [['denomination' => 100000, 'qty' => 1], ['denomination' => 50000, 'qty' => 1], ['denomination' => 5000, 'qty' => 1], ['denomination' => 2000, 'qty' => 1], ['denomination' => 1000, 'qty' => 1]]])
            ->assertOk()->assertJsonPath('expected_cash', '158000.00')->assertJsonPath('cash_difference', '0.00');
    }

    public function test_the_hour_of_a_sale_is_sent_as_a_string(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = $this->makeShift($cashier, ['opening_cash' => 0]);
        $tab = $this->apiOpenTab($shift->id)->json('id');
        $this->apiAddLine($tab, $this->makeVariant(stock: 10, price: 8000)->id, 1)->assertCreated();
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 8000]])->assertOk();

        $hour = $this->getJson("/api/v1/sessions/{$shift->id}/x-report")->assertOk()->json('hourly.0.hour');
        $this->assertIsString($hour);
        $this->assertMatchesRegularExpression('/^\d{2}$/', $hour);
    }
}
