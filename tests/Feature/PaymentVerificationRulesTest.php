<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 032-mark-payment-verified — aturan model yang dipakai bersama oleh service, guard
 * controller, aksi massal, dan presenter: pembayaran mana yang BISA diverifikasi
 * (isVerifiable) dan siapa yang BOLEH memverifikasinya (mayVerify).
 */
class PaymentVerificationRulesTest extends TestCase
{
    use RefreshDatabase;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        // 046: induk pembayaran selalu penjualan POS (pre-order sudah dihapus).
        $session = CashierSession::factory()->create(['user_id' => $owner->id]);
        $this->orderId = Order::create([
            'order_number' => 'TRX-RULES-0001', 'session_id' => $session->id, 'user_id' => $owner->id,
            'subtotal' => 100000, 'total_amount' => 100000, 'paid_amount' => 0, 'status' => 'completed',
        ])->id;
    }

    /** chk_payments_channel: non-tunai wajib punya kanal, tunai wajib tanpa kanal. */
    private function payment(array $overrides = []): Payment
    {
        $row = array_merge([
            'order_id' => $this->orderId, 'method' => 'qr_ewallet', 'purpose' => 'down_payment',
            'amount' => 50000, 'verification' => 'pending', 'paid_at' => now(),
        ], $overrides);
        $row['channel_id'] = $row['method'] === 'cash' ? null : PaymentChannel::create([
            'type' => 'qr_ewallet', 'provider' => 'Shopee', 'account_name' => 'Test', 'is_active' => true,
        ])->id;

        return Payment::create($row);
    }

    // --- isVerifiable --------------------------------------------------------------

    public function test_only_a_pending_non_cash_payment_is_verifiable(): void
    {
        $this->assertTrue($this->payment()->isVerifiable());
        $this->assertFalse($this->payment(['verification' => 'verified'])->isVerifiable());
        $this->assertFalse($this->payment(['verification' => 'rejected'])->isVerifiable());
        $this->assertFalse($this->payment(['method' => 'cash', 'verification' => 'verified'])->isVerifiable());
        $this->assertTrue($this->payment(['method' => 'bank_transfer'])->isVerifiable());
    }

    // --- mayVerify -----------------------------------------------------------------

    public function test_owner_admin_and_another_cashier_may_verify(): void
    {
        $recorder = User::factory()->create(['role' => 'cashier']);
        $payment = $this->payment(['recorded_by' => $recorder->id]);

        $this->assertTrue($payment->mayVerify(User::factory()->create(['role' => 'owner'])));
        $this->assertTrue($payment->mayVerify(User::factory()->create(['role' => 'admin'])));
        $this->assertTrue($payment->mayVerify(User::factory()->create(['role' => 'cashier'])));
    }

    public function test_the_recording_cashier_may_not_verify_their_own_payment(): void
    {
        $recorder = User::factory()->create(['role' => 'cashier']);
        $payment = $this->payment(['recorded_by' => $recorder->id]);

        $this->assertFalse($payment->mayVerify($recorder));
    }

    public function test_an_owner_or_admin_may_verify_a_payment_they_recorded_themselves(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($this->payment(['recorded_by' => $owner->id])->mayVerify($owner));
        $this->assertTrue($this->payment(['recorded_by' => $admin->id])->mayVerify($admin));
    }

    public function test_a_payment_with_no_recorded_user_may_be_verified_by_anyone(): void
    {
        $payment = $this->payment(['recorded_by' => null]);

        $this->assertTrue($payment->mayVerify(User::factory()->create(['role' => 'cashier'])));
        $this->assertTrue($payment->mayVerify(User::factory()->create(['role' => 'owner'])));
    }

    // --- verifier relation ---------------------------------------------------------

    public function test_the_verifier_relation_returns_the_verifying_user_or_null(): void
    {
        $verifier = User::factory()->create(['role' => 'owner', 'name' => 'Pemeriksa']);
        $verified = $this->payment(['verification' => 'verified', 'verified_by' => $verifier->id, 'verified_at' => now()]);
        $pending = $this->payment();

        $this->assertSame('Pemeriksa', $verified->verifier->name);
        $this->assertNull($pending->verifier);
    }
}
