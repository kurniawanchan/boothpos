<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\PaymentSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 028-partial-split-payment (Foundational) — rumus ringkasan pembayaran murni
 * (research Decision 2; 046: hanya penjualan POS): total terbayar, sisa, status, dan jumlah entri selalu
 * dihitung dari entri pembayaran, tidak dari angka yang tersimpan.
 */
class PaymentSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'cashier']);
    }

    private function order(float $total, float $paid = 0, float $change = 0): Order
    {
        $session = CashierSession::factory()->create(['user_id' => $this->user->id]);

        return Order::create([
            'order_number' => 'TRX-TEST-'.uniqid(), 'session_id' => $session->id,
            'user_id' => $this->user->id, 'subtotal' => $total, 'total_amount' => $total,
            'paid_amount' => $paid, 'change_amount' => $change, 'status' => 'completed',
        ]);
    }

    private function pay(Order $target, float $amount, string $verification = 'verified'): Payment
    {
        return Payment::create([
            'order_id' => $target->id,
            'method' => 'cash', 'purpose' => 'full', 'amount' => $amount,
            'verification' => $verification, 'paid_at' => now(),
        ]);
    }

    public function test_an_order_without_payments_is_unpaid(): void
    {
        $summary = PaymentSummary::for($this->order(1000000));

        $this->assertSame([
            'grand_total' => '1000000.00', 'total_paid' => '0.00', 'remaining' => '1000000.00',
            'status' => 'unpaid', 'payment_count' => 0,
        ], $summary);
    }

    public function test_an_order_with_part_paid_is_partially_paid_and_counts_the_entries(): void
    {
        $order = $this->order(1000000);
        $this->pay($order, 200000);
        $this->pay($order, 200000);

        $summary = PaymentSummary::for($order->fresh());

        $this->assertSame('400000.00', $summary['total_paid']);
        $this->assertSame('600000.00', $summary['remaining']);
        $this->assertSame('partially_paid', $summary['status']);
        $this->assertSame(2, $summary['payment_count']);
    }

    public function test_paying_the_exact_total_is_fully_paid_with_zero_remaining(): void
    {
        $order = $this->order(1000000);
        $this->pay($order, 400000);
        $this->pay($order, 600000);

        $summary = PaymentSummary::for($order->fresh());

        $this->assertSame('0.00', $summary['remaining']);
        $this->assertSame('fully_paid', $summary['status']);
    }

    public function test_rejected_payments_are_excluded_from_total_paid_and_the_count(): void
    {
        $order = $this->order(1000000);
        $this->pay($order, 300000);
        $this->pay($order, 500000, 'rejected');

        $summary = PaymentSummary::for($order->fresh());

        $this->assertSame('300000.00', $summary['total_paid']);
        $this->assertSame(1, $summary['payment_count']);
    }

    public function test_pending_non_cash_payments_still_count(): void
    {
        $order = $this->order(100000);
        $this->pay($order, 100000, 'pending'); // tak ada alur yang memverifikasi — tetap dihitung

        $this->assertSame('fully_paid', PaymentSummary::for($order->fresh())['status']);
    }

    public function test_an_order_paid_with_cash_change_counts_the_total_not_the_tendered_amount(): void
    {
        // total 90.000, tunai diserahkan 100.000, kembalian 10.000
        $order = $this->order(90000, paid: 100000, change: 10000);
        $this->pay($order, 100000);

        $summary = PaymentSummary::for($order->fresh());

        $this->assertSame('90000.00', $summary['total_paid']);
        $this->assertSame('0.00', $summary['remaining']);
        $this->assertSame('fully_paid', $summary['status']);
    }

    public function test_a_partially_paid_order_has_a_remaining_balance(): void
    {
        $order = $this->order(500000, paid: 200000);
        $this->pay($order, 200000);

        $summary = PaymentSummary::for($order->fresh());

        $this->assertSame('300000.00', $summary['remaining']);
        $this->assertSame('partially_paid', $summary['status']);
    }

    public function test_remaining_never_goes_negative_and_rounds_to_two_decimals(): void
    {
        $order = $this->order(100);
        $this->pay($order, 100.004);
        $this->pay($order, 0.01); // data lama yang melebihi total

        $summary = PaymentSummary::for($order->fresh());

        $this->assertSame('0.00', $summary['remaining']);
        $this->assertSame('fully_paid', $summary['status']);
    }

    public function test_the_model_helpers_delegate_to_the_same_maths(): void
    {
        $order = $this->order(1000, paid: 250);
        $this->pay($order, 250);

        $this->assertSame(PaymentSummary::for($order->fresh()), $order->fresh()->paymentSummary());
    }

    public function test_a_given_payment_collection_is_used_instead_of_a_query(): void
    {
        $order = $this->order(1000);
        $this->pay($order, 100);

        $summary = PaymentSummary::for($order, collect([]));

        $this->assertSame('unpaid', $summary['status']);
    }
}
