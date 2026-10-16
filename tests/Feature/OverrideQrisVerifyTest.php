<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / S3 — QRIS: verifikasi (referensi ≥ 4) dan penandaan tidak ditemukan hanya lewat persetujuan
 * `qris_verify` / `qris_fail`. Pencatat pembayaran tidak boleh memverifikasi sendiri, jadi penguji adalah
 * kasir lain.
 */
class OverrideQrisVerifyTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $recorder;
    private User $checker;
    private int $tab;
    private int $paymentId;
    private int $outletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = User::factory()->create(['role' => 'cashier']);
        $this->checker = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->recorder, 'sanctum');
        $shift = $this->makeShift($this->recorder);
        $this->outletId = (int) $shift->outlet_id;
        $this->tab = $this->apiOpenTab($shift->id)->json('id');
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 10000)->id, 2)->assertCreated();

        $channel = PaymentChannel::factory()->create();
        $this->apiPay($this->tab, [['method' => 'qr_ewallet', 'amount' => 5000, 'channel_id' => $channel->id]])->assertOk();
        $this->paymentId = Payment::where('order_id', $this->tab)->where('method', 'qr_ewallet')->firstOrFail()->id;
    }

    private function verify(array $body)
    {
        return $this->postJson("/api/v1/tabs/{$this->tab}/payments/{$this->paymentId}/verify", $body);
    }

    private function notFound(array $body = [])
    {
        return $this->postJson("/api/v1/tabs/{$this->tab}/payments/{$this->paymentId}/fail", $body);
    }

    public function test_verifying_a_qris_payment_without_approval_is_refused(): void
    {
        $this->actingAs($this->checker, 'sanctum');

        $this->verify(['reference' => 'REF12345'])->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertSame('pending', Payment::find($this->paymentId)->verification);
    }

    public function test_an_approved_verification_stores_the_reference_and_verifies_the_payment(): void
    {
        $this->actingAs($this->checker, 'sanctum');
        $token = $this->overrideToken('qris_verify', ['tab_id' => $this->tab, 'payment_id' => $this->paymentId, 'reference' => 'REF12345'], $this->outletId);

        $this->verify(['reference' => 'REF12345', 'override_token' => $token])->assertOk();

        $payment = Payment::find($this->paymentId);
        $this->assertSame('verified', $payment->verification);
        $this->assertSame('REF12345', $payment->reference);
    }

    public function test_a_reference_shorter_than_four_characters_is_refused_before_any_approval(): void
    {
        $this->actingAs($this->checker, 'sanctum');

        $this->verify(['reference' => 'ABC'])->assertStatus(422)->assertJsonValidationErrors('reference');
    }

    public function test_marking_a_qris_payment_not_found_without_approval_is_refused(): void
    {
        $this->actingAs($this->checker, 'sanctum');

        $this->notFound()->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertSame('pending', Payment::find($this->paymentId)->verification);
    }

    public function test_an_approved_not_found_marks_the_payment_rejected_and_releases_its_amount(): void
    {
        $this->actingAs($this->checker, 'sanctum');
        $token = $this->overrideToken('qris_fail', ['tab_id' => $this->tab, 'payment_id' => $this->paymentId], $this->outletId);

        $this->notFound(['override_token' => $token])->assertOk();

        $this->assertSame('rejected', Payment::find($this->paymentId)->verification);
        $this->assertSame('0.00', (string) Order::find($this->tab)->paid_amount);
        $this->assertSame('open', Order::find($this->tab)->status);
    }
}
