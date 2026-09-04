<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreorderTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'RYUKYFIG0001', 'sell_price' => 300000, 'cost_price' => 150000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->json();
    }

    public function test_creating_preorder_does_not_reduce_stock(): void
    {
        $this->createPreorder();
        $this->assertSame(0, $this->variant->fresh()->current_stock);
    }

    public function test_preorder_requires_customer(): void
    {
        $response = $this->postJson('/api/v1/preorders', [
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_recording_down_payment_moves_status_from_ordered_to_dp_paid(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ]);

        $response->assertCreated()->assertJsonPath('status', 'dp_paid');
        $this->assertEquals('100000.00', $response->json('paid_amount'));
    }

    public function test_arrived_status_increases_stock(): void
    {
        $preorder = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        // Lompat langsung dp_paid tidak valid tanpa payment, tapi untuk
        // menguji efek stok kita transisi manual lewat endpoint status.

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $this->assertSame(1, $this->variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'type' => 'purchase', 'qty_change' => 1]);
    }

    public function test_cannot_skip_from_ordered_directly_to_handed_over(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over']);

        $response->assertStatus(409);
    }

    public function test_handed_over_rejected_when_not_fully_paid(): void
    {
        $preorder = $this->createPreorder(); // total 300000, belum bayar
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'settled'])->assertOk();
        // Belum bayar penuh -> settled tercapai tapi handed_over harus gagal
        $handoverAttempt = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over']);

        $handoverAttempt->assertStatus(409);
        $this->assertSame(1, $this->variant->fresh()->current_stock); // stok tidak berkurang
    }

    public function test_handed_over_succeeds_when_fully_paid_and_decreases_stock(): void
    {
        $preorder = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        // Lunasi penuh (300000) — otomatis pindah ke 'settled'.
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 300000, 'purpose' => 'settlement',
        ])->assertCreated()->assertJsonPath('status', 'settled');

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over']);

        $response->assertOk()->assertJsonPath('status', 'handed_over');
        $this->assertSame(0, $this->variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'type' => 'preorder_handover', 'qty_change' => -1]);
    }

    public function test_response_includes_customer_payments_and_shipment(): void
    {
        // BUG YANG DITEMUKAN & DIPERBAIKI — ditemukan lewat verifikasi
        // browser sungguhan saat integrasi frontend, bukan lewat test yang
        // sudah ada di sini sebelumnya. present() sudah lama mengembalikan
        // 'items' tapi diam-diam tidak pernah menyertakan customer/payments/
        // shipment sama sekali di respons manapun (store/show/updateStatus/
        // storePayment), padahal ketiganya sudah didokumentasikan di
        // openapi-pos-mvp.yaml dan show() sudah meng-eager-load semuanya.

        $preorder = $this->createPreorder();
        $this->assertSame($this->customer->id, $preorder['customer']['id']);
        $this->assertSame([], $preorder['payments']);
        $this->assertNull($preorder['shipment']);

        $afterDp = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ])->json();
        $this->assertSame($this->customer->id, $afterDp['customer']['id']);
        $this->assertCount(1, $afterDp['payments']);
        $this->assertSame('down_payment', $afterDp['payments'][0]['purpose']);
        $this->assertSame('100000.00', $afterDp['payments'][0]['amount']);

        $show = $this->getJson("/api/v1/preorders/{$preorder['id']}")->json();
        $this->assertSame($this->customer->name, $show['customer']['name']);
        $this->assertCount(1, $show['payments']);

        $afterStatus = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->json();
        $this->assertSame($this->customer->id, $afterStatus['customer']['id']);
    }

    public function test_shipment_appears_in_response_once_created(): void
    {
        $product = $this->variant->product()->update(['is_preorder' => true]);
        $courierPreorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'courier',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->json();

        $this->postJson("/api/v1/preorders/{$courierPreorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
            'address_line' => 'Jl. Test', 'city' => 'Jakarta',
        ])->assertCreated();

        $show = $this->getJson("/api/v1/preorders/{$courierPreorder['id']}")->json();
        $this->assertSame('JNE', $show['shipment']['courier_name']);
    }

    public function test_shipment_can_only_be_created_for_courier_fulfillment(): void
    {
        $preorder = $this->createPreorder(); // fulfillment = pickup

        $response = $this->postJson("/api/v1/preorders/{$preorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
            'address_line' => 'Jl. Test', 'city' => 'Jakarta',
        ]);

        $response->assertStatus(409);
    }

    /**
     * 010-split-payment-preorder-reports (US2, T009) — verifikasi bahwa
     * POST /preorders/{id}/payments yang TIDAK diubah sama sekali sudah
     * benar-benar aman dipanggil berurutan (sequential) untuk mensimulasikan
     * split payment dari frontend (lihat research.md R2: tidak ada endpoint
     * batch baru, FE hanya memanggil endpoint ini N kali berturut-turut).
     */
    public function test_sequential_payment_calls_each_create_own_row_and_sum_paid_amount(): void
    {
        $preorder = $this->createPreorder(); // total 300000

        $first = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ]);
        $first->assertCreated();
        $this->assertEquals('100000.00', $first->json('paid_amount'));

        $second = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'qr_ewallet', 'amount' => 50000, 'purpose' => 'down_payment',
            'channel_id' => $this->createPaymentChannel()->id,
            'proof_token' => $this->createProofToken(),
        ]);
        $second->assertCreated();

        // 2 baris terpisah di tabel payments, bukan digabung jadi satu.
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['preorder_id' => $preorder['id'], 'method' => 'cash', 'amount' => 100000]);
        $this->assertDatabaseHas('payments', ['preorder_id' => $preorder['id'], 'method' => 'qr_ewallet', 'amount' => 50000]);

        // paid_amount/outstanding mencerminkan SUM kedua panggilan, bukan
        // hanya panggilan terakhir.
        $this->assertEquals('150000.00', $second->json('paid_amount'));
        $this->assertEquals('150000.00', $second->json('outstanding'));
    }

    public function test_sequential_down_payment_then_settlement_transitions_status_like_single_calls_would(): void
    {
        $preorder = $this->createPreorder(); // total 300000

        // Panggilan 1: down_payment via cash -> status ordered -> dp_paid,
        // identik dengan test_recording_down_payment_moves_status_from_ordered_to_dp_paid.
        $afterDp = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ]);
        $afterDp->assertCreated()->assertJsonPath('status', 'dp_paid');

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        // Panggilan 2 (split entry kedua di tahap settlement): settlement
        // via bank_transfer melunasi sisa 200000 -> status arrived -> settled,
        // identik dengan test_handed_over_succeeds_when_fully_paid_and_decreases_stock.
        $afterSettlement = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'bank_transfer', 'amount' => 200000, 'purpose' => 'settlement',
            'channel_id' => $this->createPaymentChannel()->id,
            'proof_token' => $this->createProofToken(),
        ]);
        $afterSettlement->assertCreated()->assertJsonPath('status', 'settled');
        $this->assertEquals('300000.00', $afterSettlement->json('paid_amount'));
        $this->assertEquals('0.00', $afterSettlement->json('outstanding'));

        $this->assertDatabaseCount('payments', 2);
    }

    /**
     * BUG-CHECK (bukan bug baru) — dibaca dari PreorderService::recordPayment()
     * dan PaymentRecorder::record(): tidak ada guard "tolak jika amount >
     * outstanding" untuk preorder sama sekali (berbeda dari cash-overpay
     * guard di alur POS/orders). Ini perilaku existing yang tidak diubah
     * oleh fitur 010 — jadi panggilan ketiga setelah lunas TETAP diterima
     * (201) dan menambah paid_amount melebihi total_amount, sama seperti
     * satu panggilan over-payment tunggal hari ini juga akan diterima.
     * Test ini mengunci perilaku itu supaya regresi (guard baru yang tidak
     * disengaja, atau guard yang hilang) ketahuan.
     */
    public function test_third_sequential_call_after_fully_paid_is_still_accepted_no_overpay_guard_exists(): void
    {
        $preorder = $this->createPreorder(); // total 300000

        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ])->assertCreated();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 200000, 'purpose' => 'settlement',
        ])->assertCreated()->assertJsonPath('status', 'settled');

        // Panggilan ketiga: preorder sudah lunas (paid_amount == total_amount),
        // tapi karena tidak ada guard over-payment untuk preorder, panggilan
        // ini tetap sukses (201), bukan ditolak.
        $third = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 50000, 'purpose' => 'settlement',
        ]);

        $third->assertCreated();
        $this->assertEquals('350000.00', $third->json('paid_amount'));
        $this->assertDatabaseCount('payments', 3);
    }

    private function createProofToken(): string
    {
        $proof = \App\Models\PaymentProof::create([
            'proof_token' => (string) \Illuminate\Support\Str::uuid(),
            'file_path' => 'payment-proofs/test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'uploaded_by' => \App\Models\User::first()->id,
        ]);

        return $proof->proof_token;
    }

    private function createPaymentChannel(): \App\Models\PaymentChannel
    {
        return \App\Models\PaymentChannel::create([
            'type' => 'bank_transfer', 'provider' => 'BCA',
            'account_name' => 'Test', 'account_number' => '123', 'is_active' => true,
        ]);
    }
}
