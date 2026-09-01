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
}
