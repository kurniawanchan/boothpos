<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 031-optional-payment-proof — aturan model yang dipakai bersama oleh service,
 * guard controller, presenter, dan PaymentProofController: siapa boleh mengubah
 * konfirmasi pembayaran, siapa boleh membuka bukti, dan mana "bukti yang berlaku".
 */
class PaymentConfirmationRulesTest extends TestCase
{
    use RefreshDatabase;

    private int $preorderId;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $variant = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ])->variants()->create(['sku' => 'CNF0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
        $this->preorderId = $this->postJson('/api/v1/preorders', [
            'customer_id' => Customer::factory()->create()->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ])->assertCreated()->json('id');
    }

    private function channelId(): int
    {
        return \App\Models\PaymentChannel::create([
            'type' => 'qr_ewallet', 'provider' => 'Shopee', 'account_name' => 'Test', 'is_active' => true,
        ])->id;
    }

    /** chk_payments_channel: non-tunai wajib punya kanal, tunai wajib tanpa kanal. */
    private function payment(array $overrides = []): Payment
    {
        $row = array_merge([
            'preorder_id' => $this->preorderId, 'method' => 'qr_ewallet', 'purpose' => 'down_payment',
            'amount' => 50000, 'verification' => 'pending', 'paid_at' => now(),
        ], $overrides);
        $row['channel_id'] = $row['method'] === 'cash' ? null : $this->channelId();

        return Payment::create($row);
    }

    private function proof(Payment $payment, ?User $uploader, array $overrides = []): PaymentProof
    {
        return PaymentProof::create(array_merge([
            'proof_token' => (string) \Illuminate\Support\Str::uuid(), 'payment_id' => $payment->id,
            'file_path' => 'payment-proofs/'.uniqid().'.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10,
            'captured_via' => 'upload', 'uploaded_by' => $uploader?->id, 'created_at' => now(),
        ], $overrides));
    }

    // --- confirmationEditableBy ----------------------------------------------------

    public function test_owner_and_admin_may_edit_any_non_cash_confirmation(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $payment = $this->payment(['recorded_by' => $cashier->id]);

        $this->assertTrue($payment->confirmationEditableBy(User::factory()->create(['role' => 'owner'])));
        $this->assertTrue($payment->confirmationEditableBy(User::factory()->create(['role' => 'admin'])));
    }

    public function test_the_recording_cashier_may_edit_but_another_cashier_may_not(): void
    {
        $recorder = User::factory()->create(['role' => 'cashier']);
        $other = User::factory()->create(['role' => 'cashier']);
        $payment = $this->payment(['recorded_by' => $recorder->id]);

        $this->assertTrue($payment->confirmationEditableBy($recorder));
        $this->assertFalse($payment->confirmationEditableBy($other));
        $this->assertFalse($payment->confirmationEditableBy(User::factory()->create(['role' => 'inventory'])));
    }

    public function test_a_payment_with_no_recorded_user_is_editable_by_owner_or_admin_only(): void
    {
        $payment = $this->payment(['recorded_by' => null]);

        $this->assertFalse($payment->confirmationEditableBy(User::factory()->create(['role' => 'cashier'])));
        $this->assertTrue($payment->confirmationEditableBy(User::factory()->create(['role' => 'owner'])));
    }

    public function test_a_cash_payment_has_no_editable_confirmation_for_anyone(): void
    {
        $recorder = User::factory()->create(['role' => 'cashier']);
        $payment = $this->payment(['method' => 'cash', 'recorded_by' => $recorder->id]);

        $this->assertFalse($payment->confirmationEditableBy($recorder));
        $this->assertFalse($payment->confirmationEditableBy(User::factory()->create(['role' => 'owner'])));
    }

    // --- currentProof --------------------------------------------------------------

    public function test_current_proof_is_null_without_proofs_and_the_only_proof_otherwise(): void
    {
        $payment = $this->payment();
        $this->assertNull($payment->currentProof());

        $only = $this->proof($payment, null);
        $this->assertSame($only->id, $payment->fresh()->currentProof()->id);
    }

    public function test_current_proof_skips_superseded_rows_and_takes_the_latest_remaining(): void
    {
        $payment = $this->payment();
        $old = $this->proof($payment, null, ['superseded_at' => now()->subDay()]);
        $newer = $this->proof($payment, null);

        $this->assertSame($newer->id, $payment->fresh()->currentProof()->id);
        $this->assertNotSame($old->id, $payment->fresh()->currentProof()->id);
    }

    public function test_current_proof_is_null_when_every_proof_is_superseded(): void
    {
        $payment = $this->payment();
        $this->proof($payment, null, ['superseded_at' => now()]);

        $this->assertNull($payment->fresh()->currentProof());
    }

    // --- proofViewableBy -----------------------------------------------------------

    public function test_owner_admin_and_the_uploader_may_view_a_proof(): void
    {
        $uploader = User::factory()->create(['role' => 'cashier']);
        $payment = $this->payment();
        $proof = $this->proof($payment, $uploader);

        $this->assertTrue($payment->proofViewableBy(User::factory()->create(['role' => 'owner']), $proof));
        $this->assertTrue($payment->proofViewableBy(User::factory()->create(['role' => 'admin']), $proof));
        $this->assertTrue($payment->proofViewableBy($uploader, $proof));
    }

    public function test_the_recorder_may_view_the_current_proof_someone_else_added(): void
    {
        $recorder = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $payment = $this->payment(['recorded_by' => $recorder->id]);
        $proof = $this->proof($payment, $owner);

        $this->assertTrue($payment->proofViewableBy($recorder, $proof));
        $this->assertFalse($payment->proofViewableBy(User::factory()->create(['role' => 'cashier']), $proof));
    }

    public function test_the_recorder_may_not_view_a_superseded_proof(): void
    {
        $recorder = User::factory()->create(['role' => 'cashier']);
        $owner = User::factory()->create(['role' => 'owner']);
        $payment = $this->payment(['recorded_by' => $recorder->id]);
        $old = $this->proof($payment, $owner, ['superseded_at' => now()]);
        $this->proof($payment, $owner);

        $this->assertFalse($payment->proofViewableBy($recorder, $old));
        $this->assertTrue($payment->proofViewableBy($owner, $old)); // owner/admin: akses audit
    }
}
