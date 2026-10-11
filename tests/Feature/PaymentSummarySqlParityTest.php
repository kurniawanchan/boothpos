<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\User;
use App\Support\PaymentSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T012) — rumus ringkasan pembayaran versi SQL harus SAMA dengan PaymentSummary::for()
 * untuk setiap kombinasi, supaya tab Dashboard tidak pernah berbeda dari detail transaksi.
 */
class PaymentSummarySqlParityTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function orderWith(float $total, array $payments, float $change = 0.0): Order
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $session = $this->makeShift($user);
        $order = $this->makeOpenOrder($session, $user, ['total_amount' => $total, 'subtotal' => $total, 'change_amount' => $change]);
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
        foreach ($payments as [$method, $amount, $verification]) {
            Payment::create([
                'order_id' => $order->id, 'method' => $method, 'channel_id' => $method === 'cash' ? null : $channel->id, 'purpose' => 'full', 'amount' => $amount,
                'verification' => $verification, 'paid_at' => now(),
            ]);
        }

        return $order->fresh('payments');
    }

    public function test_the_sql_status_equals_the_php_status_for_every_combination(): void
    {
        $cases = [
            'no payments' => [50000, [], 0],
            'partial cash' => [50000, [['cash', 20000, 'verified']], 0],
            'exact cash' => [50000, [['cash', 50000, 'verified']], 0],
            'overpaid with change' => [50000, [['cash', 60000, 'verified']], 10000],
            'pending qris covers it' => [50000, [['qr_ewallet', 50000, 'pending']], 0],
            'rejected only' => [50000, [['qr_ewallet', 50000, 'rejected']], 0],
            'split cash + verified transfer' => [50000, [['cash', 30000, 'verified'], ['bank_transfer', 20000, 'verified']], 0],
            'rejected plus partial cash' => [50000, [['cash', 20000, 'verified'], ['qr_ewallet', 30000, 'rejected']], 0],
            'free order' => [0, [], 0],
        ];

        foreach ($cases as $name => [$total, $payments, $change]) {
            $order = $this->orderWith($total, $payments, $change);
            $php = PaymentSummary::for($order);
            $row = DB::table('orders')->where('orders.id', $order->id)
                ->selectRaw(PaymentSummary::orderStatusSql().' as status, '.PaymentSummary::orderRemainingSql().' as remaining, '.PaymentSummary::orderPaymentCountSql().' as n')
                ->first();

            $this->assertSame($php['status'], $row->status, "status — {$name}");
            $this->assertEqualsWithDelta((float) $php['remaining'], (float) $row->remaining, 0.001, "remaining — {$name}");
            $this->assertSame($php['payment_count'], (int) $row->n, "count — {$name}");
        }
    }

    public function test_the_pending_non_cash_flag(): void
    {
        $pending = $this->orderWith(50000, [['qr_ewallet', 50000, 'pending']]);
        $cash = $this->orderWith(50000, [['cash', 50000, 'verified']]);

        $flag = fn (Order $o) => (bool) DB::table('orders')->where('orders.id', $o->id)->selectRaw(PaymentSummary::orderHasPendingNonCashSql().' as f')->value('f');

        $this->assertTrue($flag($pending));
        $this->assertFalse($flag($cash));
    }
}
