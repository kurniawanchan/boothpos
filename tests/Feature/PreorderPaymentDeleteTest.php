<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentProof;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DELETE /preorders/{preorder}/payments/{payment} — hapus satu pembayaran
 * (beserta bukti bayarnya) dan hitung ulang paid_amount + status dari sisa
 * pembayaran. Hanya owner/admin; ditolak untuk status tertutup.
 */
class PreorderPaymentDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ProductVariant $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'PDEL0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    /** Total 200.000 (2 × 100.000). */
    private function order(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json();
    }

    private function pay(int $preorderId, float $amount, string $purpose = 'down_payment', bool $withProof = false): array
    {
        $payload = ['method' => 'cash', 'amount' => $amount, 'purpose' => $purpose];

        if ($withProof) {
            $token = $this->postJson('/api/v1/payment-proofs', [
                'file' => UploadedFile::fake()->image('bukti.jpg'), 'captured_via' => 'upload',
            ])->assertCreated()->json('proof_token');
            $channel = \App\Models\PaymentChannel::create([
                'type' => 'bank_transfer', 'provider' => 'BCA',
                'account_name' => 'Test', 'account_number' => '123', 'is_active' => true,
            ]);
            $payload = ['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => $amount, 'purpose' => $purpose, 'proof_token' => $token];
        }

        $response = $this->postJson("/api/v1/preorders/{$preorderId}/payments", $payload)->assertCreated();

        return collect($response->json('payments'))->last();
    }

    private function remove(int $preorderId, int $paymentId)
    {
        return $this->deleteJson("/api/v1/preorders/{$preorderId}/payments/{$paymentId}");
    }

    public function test_deleting_the_only_deposit_sends_the_status_back_to_ordered(): void
    {
        $order = $this->order();
        $payment = $this->pay($order['id'], 50000);
        $this->assertSame('dp_paid', Preorder::findOrFail($order['id'])->status);

        $response = $this->remove($order['id'], $payment['id'])->assertOk();

        $response->assertJsonPath('status', 'ordered')
            ->assertJsonPath('paid_amount', '0.00')
            ->assertJsonPath('outstanding', '200000.00')
            ->assertJsonPath('payments', []);
        $this->assertDatabaseMissing('payments', ['id' => $payment['id']]);
    }

    public function test_the_proof_row_and_its_file_are_removed_with_the_payment(): void
    {
        $order = $this->order();
        $payment = $this->pay($order['id'], 50000, 'down_payment', withProof: true);
        $proof = PaymentProof::where('payment_id', $payment['id'])->firstOrFail();
        Storage::disk('local')->assertExists($proof->file_path);

        $this->remove($order['id'], $payment['id'])->assertOk();

        $this->assertDatabaseMissing('payment_proofs', ['id' => $proof->id]);
        Storage::disk('local')->assertMissing($proof->file_path);
    }

    public function test_deleting_one_of_two_payments_keeps_deposit_paid_and_recomputes_the_sum(): void
    {
        $order = $this->order();
        $first = $this->pay($order['id'], 50000);
        $second = $this->pay($order['id'], 30000, 'settlement');

        $response = $this->remove($order['id'], $second['id'])->assertOk();

        $response->assertJsonPath('status', 'dp_paid')->assertJsonPath('paid_amount', '50000.00');
        $this->assertDatabaseHas('payments', ['id' => $first['id']]);
    }

    public function test_deleting_the_payment_that_settled_an_order_sends_it_back_to_goods_arrived(): void
    {
        $order = $this->order();
        $this->pay($order['id'], 50000);
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'arrived'])->assertOk();
        $final = $this->pay($order['id'], 150000, 'settlement');
        $this->assertSame('settled', Preorder::findOrFail($order['id'])->status);

        $response = $this->remove($order['id'], $final['id'])->assertOk();

        $response->assertJsonPath('status', 'arrived')
            ->assertJsonPath('paid_amount', '50000.00')
            ->assertJsonPath('outstanding', '150000.00');
    }

    public function test_an_arrived_order_stays_arrived_when_its_last_payment_is_deleted(): void
    {
        $order = $this->order();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$order['id']}/status", ['status' => 'arrived'])->assertOk();
        $only = $this->pay($order['id'], 200000, 'full');
        $this->assertSame('settled', Preorder::findOrFail($order['id'])->status);

        // barang sudah tiba (stok sudah masuk) — tidak boleh mundur ke "ordered"
        $this->remove($order['id'], $only['id'])->assertOk()
            ->assertJsonPath('status', 'arrived')->assertJsonPath('paid_amount', '0.00');
    }

    public function test_handed_over_and_cancelled_orders_refuse_payment_deletion(): void
    {
        foreach (['handed_over', 'cancelled'] as $status) {
            $order = $this->order();
            $payment = $this->pay($order['id'], 50000);
            Preorder::whereKey($order['id'])->update(['status' => $status]);

            $this->remove($order['id'], $payment['id'])->assertStatus(409);

            $this->assertDatabaseHas('payments', ['id' => $payment['id']]);
            $this->assertEquals(50000, Preorder::findOrFail($order['id'])->paid_amount);
        }
    }

    public function test_a_payment_of_another_pre_order_is_not_found(): void
    {
        $a = $this->order();
        $b = $this->order();
        $payment = $this->pay($b['id'], 50000);

        $this->remove($a['id'], $payment['id'])->assertNotFound();

        $this->assertDatabaseHas('payments', ['id' => $payment['id']]);
    }

    public function test_only_owner_or_admin_may_delete_a_payment(): void
    {
        $order = $this->order();
        $payment = $this->pay($order['id'], 50000);

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->remove($order['id'], $payment['id'])->assertForbidden();
        $this->assertDatabaseHas('payments', ['id' => $payment['id']]);

        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->remove($order['id'], $payment['id'])->assertOk();
    }

    public function test_the_deletion_is_written_to_the_activity_log(): void
    {
        $order = $this->order();
        $payment = $this->pay($order['id'], 50000);

        $this->remove($order['id'], $payment['id'])->assertOk();

        $log = \App\Models\ActivityLog::where('action', 'payment_deleted')->firstOrFail();
        $this->assertSame('Preorder', $log->entity_type);
        $this->assertSame($order['id'], $log->entity_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('dp_paid', $log->old_values['status']);
        $this->assertSame('ordered', $log->new_values['status']);
        $this->assertEquals(50000, $log->old_values['amount']);
    }

    public function test_after_deleting_the_payment_the_order_can_be_split_and_deleted_again(): void
    {
        $order = $this->order();
        $payment = $this->pay($order['id'], 50000);
        $this->remove($order['id'], $payment['id'])->assertOk();

        $this->deleteJson("/api/v1/preorders/{$order['id']}")->assertNoContent();
    }
}
