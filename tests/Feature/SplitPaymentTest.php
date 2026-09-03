<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 006-purchase-order-and-ops (US2) — split payment. Confirms
 * OrderService::create()'s existing sum/guard logic (already proven by
 * OrderTest.php's single-payment cases) still holds once a request
 * actually sends 2+ entries, per research.md R2.
 */
class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private CashierSession $session;
    private ProductVariant $variant;
    private PaymentChannel $qrisChannel;

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
            'sku' => 'SPLIT0001', 'sell_price' => 50000, 'cost_price' => 20000, 'current_stock' => 10,
        ]);
        $this->qrisChannel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
    }

    private function basePayload(array $payments): array
    {
        return [
            'session_id' => $this->session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => $payments,
        ];
    }

    public function test_two_entries_summing_exactly_to_total_succeeds_and_both_are_persisted(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            ['method' => 'cash', 'amount' => 30000],
            ['method' => 'qr_ewallet', 'channel_id' => $this->qrisChannel->id, 'amount' => 20000, 'proof_token' => $this->fakeProofToken()],
        ]));

        $response->assertCreated()->assertJsonPath('total_amount', '50000.00')->assertJsonPath('paid_amount', '50000.00');
        $orderId = $response->json('id');
        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'method' => 'cash', 'amount' => 30000]);
        $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'method' => 'qr_ewallet', 'amount' => 20000]);
    }

    public function test_entries_summing_less_than_total_are_rejected(): void
    {
        $this->postJson('/api/v1/orders', $this->basePayload([
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'cash', 'amount' => 10000],
        ]))->assertStatus(409);
    }

    public function test_non_cash_overpay_not_covered_by_the_cash_portion_is_rejected(): void
    {
        // total due = 50000; paid = 60000 (5000 cash + 55000 QRIS) — the
        // 10000 "change" this would imply exceeds the 5000 actually paid
        // in cash, so it can't be change at all; only cash can ever
        // produce change (research.md/spec edge case).
        $this->postJson('/api/v1/orders', $this->basePayload([
            ['method' => 'cash', 'amount' => 5000],
            ['method' => 'qr_ewallet', 'channel_id' => $this->qrisChannel->id, 'amount' => 55000, 'proof_token' => $this->fakeProofToken()],
        ]))->assertStatus(409);
    }

    public function test_cash_entry_may_still_exceed_total_for_change(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload([
            ['method' => 'cash', 'amount' => 100000],
        ]));

        $response->assertCreated()->assertJsonPath('change_amount', '50000.00');
    }

    private function fakeProofToken(): string
    {
        $token = (string) \Illuminate\Support\Str::uuid();
        \App\Models\PaymentProof::create(['proof_token' => $token, 'file_path' => 'test/fake.jpg', 'original_name' => 'fake.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'created_at' => now()]);

        return $token;
    }
}
