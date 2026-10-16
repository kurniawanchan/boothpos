<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ShiftReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / T060 — refund penuh order lunas: selalu butuh persetujuan, stok kembali lewat StockService, kas tunai
 * dikembalikan dari shift berjalan (kas keluar `refund`), dan order asal serta laporan lama TIDAK diubah.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use OverrideFixtures;

    private User $cashier;

    private CashierSession $shift;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 100000]);
        $this->variantId = $this->makeVariant(stock: 10, price: 8000)->id;
    }

    /** @param  array<int, array<string, mixed>>  $payments */
    private function sale(array $payments = [['method' => 'cash', 'amount' => 8000]]): int
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variantId, 'qty' => 1]],
            'payments' => $payments,
        ])->assertCreated()->json('id');
    }

    private function refund(int $orderId, ?string $token = null)
    {
        $body = ['reason_code' => 'wrong_item', 'reason' => 'salah pesan'] + ($token !== null ? ['override_token' => $token] : []);

        return $this->postJson("/api/v1/orders/{$orderId}/refund", $body);
    }

    private function approval(int $orderId, string $reason = 'wrong_item', string $reasonNote = 'salah pesan'): string
    {
        return $this->overrideToken('refund', ['order_id' => $orderId, 'reason_code' => $reason, 'reason' => $reasonNote], (int) $this->shift->outlet_id);
    }

    public function test_a_refund_with_an_approval_returns_stock_and_the_cash_from_the_current_shift(): void
    {
        $order = $this->sale();
        $before = StockMovement::where('reference_type', 'order_item')->where('type', 'sale')->count();

        $this->refund($order, $this->approval($order))
            ->assertOk()
            ->assertJsonPath('refund_total', '8000.00')
            ->assertJsonPath('cash_returned', '8000.00');

        $this->assertDatabaseHas('cash_movements', ['session_id' => $this->shift->id, 'direction' => 'out', 'reason_code' => 'refund', 'amount' => 8000]);
        $this->assertTrue(StockMovement::where('type', 'return')->where('reference_type', 'order_item')->exists(), 'stok harus kembali');
        $this->assertSame($before, StockMovement::where('reference_type', 'order_item')->where('type', 'sale')->count(), 'penjualan asal tidak ditulis ulang');

        // Kas seharusnya: modal 100.000 + tunai penjualan 8.000 − refund 8.000 = 100.000.
        $this->assertEqualsWithDelta(100000, app(ShiftReportService::class)->expectedCash($this->shift->fresh()), 0.001);
    }

    public function test_the_historical_order_is_not_rewritten(): void
    {
        $order = $this->sale();
        $this->refund($order, $this->approval($order))->assertOk();

        $o = Order::find($order);
        $this->assertSame('completed', $o->status);
        $this->assertSame('8000.00', (string) $o->total_amount);
        $this->assertSame('8000.00', (string) $o->paid_amount);
        $this->assertNotNull($o->refunded_at);
        $this->assertSame('wrong_item', $o->refund_reason_code);
    }

    public function test_a_refund_always_needs_an_approval_even_for_the_owner(): void
    {
        $order = $this->sale();

        $this->refund($order)->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertNull(Order::find($order)->refunded_at);
        $this->assertSame(0, CashMovement::where('reason_code', 'refund')->count());
    }

    public function test_an_approval_for_one_order_cannot_refund_another(): void
    {
        $a = $this->sale();
        $b = $this->sale();

        $this->refund($b, $this->approval($a))->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertNull(Order::find($b)->refunded_at);
    }

    public function test_an_order_cannot_be_refunded_twice(): void
    {
        $order = $this->sale();
        $this->refund($order, $this->approval($order))->assertOk();

        $this->refund($order, $this->approval($order))->assertStatus(409)->assertJsonPath('code', 'order_already_refunded');
    }

    public function test_an_open_tab_or_a_voided_order_cannot_be_refunded(): void
    {
        $tab = $this->apiOpenTab($this->shift->id)->json('id');
        $this->apiAddLine($tab, $this->variantId, 1)->assertCreated();
        $this->refund($tab, $this->approval($tab))->assertStatus(409)->assertJsonPath('code', 'order_not_refundable');

        $voided = $this->sale();
        Order::whereKey($voided)->update(['status' => 'voided']);
        $this->refund($voided, $this->approval($voided))->assertStatus(409)->assertJsonPath('code', 'order_not_refundable');
    }

    public function test_an_order_that_is_not_fully_paid_cannot_be_refunded(): void
    {
        $order = $this->sale();
        Order::whereKey($order)->update(['paid_amount' => 1000]);

        $this->refund($order, $this->approval($order))->assertStatus(409)->assertJsonPath('code', 'order_not_fully_paid');
    }

    public function test_a_refund_needs_an_open_shift_for_the_refunding_user(): void
    {
        $order = $this->sale();
        CashierSession::whereKey($this->shift->id)->update(['status' => 'closed', 'closed_at' => now()]);

        $this->refund($order, $this->approval($order))->assertStatus(409)->assertJsonPath('code', 'shift_required');
    }

    public function test_a_refund_of_a_non_cash_order_moves_no_cash(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);
        $order = $this->sale([['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 8000]]);

        $this->refund($order, $this->approval($order))->assertOk()->assertJsonPath('cash_returned', '0.00');
        $this->assertSame(0, CashMovement::where('reason_code', 'refund')->count());
    }

    public function test_a_pending_qris_on_the_order_blocks_the_refund(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
        $order = $this->sale([['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 8000]]);

        $this->refund($order, $this->approval($order))->assertStatus(409)->assertJsonPath('code', 'pending_payments');
    }
}
