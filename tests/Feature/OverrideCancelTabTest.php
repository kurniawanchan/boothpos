<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/** 048 / S3 — pembatalan seluruh tab: 409 has_payments bila sudah dibayar; selain itu harus lewat `cancel_order`. */
class OverrideCancelTabTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private CashierSession $shift;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier);
        $this->tab = $this->apiOpenTab($this->shift->id)->assertCreated()->json('id');
        $variant = $this->makeVariant(stock: 10, price: 10000);
        $this->apiAddLine($this->tab, $variant->id, 2)->assertCreated();
    }

    private const BODY = ['reason_code' => 'customer_request', 'reason' => 'tamu batal'];

    private function cancel(array $body)
    {
        return $this->postJson("/api/v1/tabs/{$this->tab}/cancel", $body);
    }

    public function test_cancelling_a_tab_without_approval_is_refused_and_the_tab_stays_open(): void
    {
        $this->cancel(self::BODY)->assertStatus(409)->assertJsonPath('code', 'override_required');

        $this->assertSame('open', Order::find($this->tab)->status);
    }

    public function test_an_approved_cancellation_voids_the_tab(): void
    {
        $token = $this->overrideToken('cancel_order', ['tab_id' => $this->tab] + self::BODY, (int) $this->shift->outlet_id);

        $this->cancel(self::BODY + ['override_token' => $token])->assertOk()->assertJsonPath('status', 'voided');
        $this->assertSame('voided', Order::find($this->tab)->status);
    }

    public function test_an_approval_for_another_action_cannot_cancel_the_tab(): void
    {
        $token = $this->overrideToken('void_line', ['tab_id' => $this->tab, 'line_id' => 1, 'reason_code' => 'customer_request', 'kitchen_status' => 'not_started', 'stock_decision' => 'return', 'reason' => 'tamu batal'], (int) $this->shift->outlet_id);

        $this->cancel(self::BODY + ['override_token' => $token])->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertSame('open', Order::find($this->tab)->status);
    }

    public function test_a_tab_that_has_been_paid_cannot_be_cancelled_even_with_approval(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 5000]])->assertOk()->assertJsonPath('status', 'open');
        $token = $this->overrideToken('cancel_order', ['tab_id' => $this->tab] + self::BODY, (int) $this->shift->outlet_id);

        $this->cancel(self::BODY + ['override_token' => $token])->assertStatus(409)->assertJsonPath('code', 'has_payments');
        $this->assertSame('open', Order::find($this->tab)->status);
    }
}
