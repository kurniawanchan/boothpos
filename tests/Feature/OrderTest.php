<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\CashierSession;
use App\Models\Event;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private CashierSession $session;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id]);
        $this->variant = $product->variants()->create([
            'sku' => 'RYUKYSAK0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 10,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'session_id' => $this->session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ], $overrides);
    }

    // --- Positive ---------------------------------------------------

    public function test_cash_order_succeeds_and_reduces_stock(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload());

        $response->assertCreated()->assertJsonPath('total_amount', '50000.00');
        $this->assertSame(8, $this->variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'type' => 'sale', 'qty_change' => -2]);
    }

    public function test_order_price_uses_server_master_data_not_client_input(): void
    {
        // Klien mencoba mengirim variant_id yang benar tapi tidak ada
        // field harga di payload sama sekali — dan memang tidak bisa,
        // karena rule tidak mendefinisikan field harga pada items.*.
        // Uji ini memastikan total dihitung dari sell_price server (25000),
        // bukan angka lain manapun.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ]));

        $response->assertCreated()->assertJsonPath('total_amount', '25000.00');
    }

    // --- Negative: idempotensi -------------------------------------

    public function test_duplicate_local_ref_returns_existing_order_not_a_new_one(): void
    {
        $payload = $this->basePayload();

        $first = $this->postJson('/api/v1/orders', $payload)->json('id');
        $second = $this->postJson('/api/v1/orders', $payload)->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, \App\Models\Order::count());
        // Stok hanya berkurang SEKALI meski request dikirim dua kali.
        $this->assertSame(8, $this->variant->fresh()->current_stock);
    }

    // --- Negative: stok --------------------------------------------

    public function test_order_rejected_when_stock_insufficient(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'items' => [['variant_id' => $this->variant->id, 'qty' => 999]],
            'payments' => [['method' => 'cash', 'amount' => 999 * 25000]],
        ]));

        $response->assertStatus(409);
        $this->assertSame(10, $this->variant->fresh()->current_stock); // tidak berubah sama sekali
    }

    // --- Negative: pembayaran ----------------------------------------

    public function test_order_rejected_when_payment_does_not_cover_total(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'payments' => [['method' => 'cash', 'amount' => 10000]], // total seharusnya 50000
        ]));

        $response->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_non_cash_payment_without_proof_token_is_rejected(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);

        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'payments' => [['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 50000]],
        ]));

        $response->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_non_cash_payment_with_valid_proof_token_succeeds(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);
        $proofResponse = $this->postJson('/api/v1/payment-proofs', [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg'),
            'captured_via' => 'upload',
        ]);
        $token = $proofResponse->json('proof_token');

        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'payments' => [[
                'method' => 'bank_transfer', 'channel_id' => $channel->id,
                'amount' => 50000, 'proof_token' => $token,
            ]],
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('payment_proofs', ['proof_token' => $token]);
        $this->assertDatabaseMissing('payment_proofs', ['proof_token' => $token, 'payment_id' => null]);
    }

    public function test_reusing_a_consumed_proof_token_is_rejected(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'bank_transfer']);
        $token = $this->postJson('/api/v1/payment-proofs', [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg'),
            'captured_via' => 'upload',
        ])->json('proof_token');

        $paymentPayload = ['method' => 'bank_transfer', 'channel_id' => $channel->id, 'amount' => 50000, 'proof_token' => $token];

        $this->postJson('/api/v1/orders', $this->basePayload(['payments' => [$paymentPayload]]))->assertCreated();

        // local_ref baru supaya tidak kena jalur idempotensi, tapi
        // proof_token yang SAMA dipakai lagi.
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'payments' => [$paymentPayload],
        ]));

        $response->assertStatus(409);
    }

    // --- Negative: sesi kasir -----------------------------------------

    public function test_order_rejected_when_session_already_closed(): void
    {
        $this->session->update(['status' => 'closed']);

        $response = $this->postJson('/api/v1/orders', $this->basePayload());

        $response->assertStatus(409);
    }

    // --- Void ---------------------------------------------------------

    public function test_cashier_cannot_void_order(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();

        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Salah input'])
            ->assertStatus(403);
    }

    public function test_owner_can_void_order_and_stock_is_restored(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();
        $this->assertSame(8, $this->variant->fresh()->current_stock);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Salah input']);

        $response->assertOk()->assertJsonPath('status', 'voided');
        $this->assertSame(10, $this->variant->fresh()->current_stock);
    }

    public function test_voiding_an_already_voided_order_is_rejected(): void
    {
        $order = $this->postJson('/api/v1/orders', $this->basePayload())->json();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Pertama'])->assertOk();
        $this->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Kedua'])->assertStatus(409);
    }
}
