<?php

namespace Tests\Feature;

use App\Models\PaymentChannel;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 031-optional-payment-proof (research Decision 1) — bukti bayar menjadi opsional
 * untuk penjualan dan pre-order, tetapi pembayaran ke pemasok pada purchase order
 * TIDAK diubah: non-tunai tetap wajib punya bukti. Tes ini mengunci batas itu.
 */
class PurchaseOrderPaymentProofRuleTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $this->po = PurchaseOrder::factory()->create(['created_by' => $owner->id, 'status' => 'received', 'total_amount' => 100000]);
    }

    public function test_a_non_cash_purchase_order_payment_without_a_proof_is_still_refused(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);

        $this->postJson("/api/v1/purchase-orders/{$this->po->id}/payments", [
            'method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 40000,
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_cash_purchase_order_payment_still_needs_no_proof(): void
    {
        $this->postJson("/api/v1/purchase-orders/{$this->po->id}/payments", [
            'method' => 'cash', 'amount' => 40000,
        ])->assertCreated();
    }
}
