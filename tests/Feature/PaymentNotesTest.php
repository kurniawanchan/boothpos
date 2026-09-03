<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 006-purchase-order-and-ops (US3) — Payment.notes is a pre-existing
 * column, already forwarded by PaymentRecorder::record(); this only
 * exercises the newly-added payments.*.notes validation rule on the
 * POS checkout path (preorder's storePayment() already validated notes
 * before this feature — see research.md R3).
 */
class PaymentNotesTest extends TestCase
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
            'sku' => 'NOTES0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 10,
        ]);
    }

    private function basePayload(array $paymentOverrides = []): array
    {
        return [
            'session_id' => $this->session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [array_merge(['method' => 'cash', 'amount' => 25000], $paymentOverrides)],
        ];
    }

    public function test_a_note_on_a_payment_is_persisted(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload(['notes' => 'Uang robek, sudah diverifikasi.']));

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['order_id' => $response->json('id'), 'notes' => 'Uang robek, sudah diverifikasi.']);
    }

    public function test_omitting_the_note_leaves_it_null_not_an_error(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->basePayload());

        $response->assertCreated();
        $this->assertDatabaseHas('payments', ['order_id' => $response->json('id'), 'notes' => null]);
    }

    public function test_a_note_exceeding_the_length_limit_is_rejected(): void
    {
        $this->postJson('/api/v1/orders', $this->basePayload(['notes' => str_repeat('a', 1001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payments.0.notes');
    }
}
