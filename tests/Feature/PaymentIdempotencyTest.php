<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 028-partial-split-payment (US4) — pengaman alur pembayaran: kelebihan bayar
 * ditolak, transaksi lunas/tertutup menolak pembayaran baru, dan klik ganda /
 * coba-ulang (kunci `client_ref`) tak pernah mencatat pembayaran dua kali.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private \App\Models\ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->user, 'sanctum');

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'IDEM0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    /** Total 200.000. */
    private function order(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertCreated()->json();
    }

    private function pay(int $id, float $amount, ?string $ref = null)
    {
        return $this->postJson("/api/v1/preorders/{$id}/payments", array_filter([
            'method' => 'cash', 'amount' => $amount, 'purpose' => 'down_payment', 'client_ref' => $ref,
        ], fn ($v) => $v !== null));
    }

    // --- idempotensi ---------------------------------------------------------

    public function test_the_same_client_ref_on_the_same_transaction_records_one_payment(): void
    {
        $order = $this->order();
        $ref = (string) Str::uuid();

        $first = $this->pay($order['id'], 50000, $ref)->assertCreated();
        $second = $this->pay($order['id'], 50000, $ref)->assertOk();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($first->json('payment_summary'), $second->json('payment_summary'));
        $second->assertJsonPath('payment_summary.payment_count', 1)->assertJsonPath('paid_amount', '50000.00');
    }

    public function test_a_replay_is_still_answered_after_the_transaction_became_fully_paid(): void
    {
        $order = $this->order();
        $ref = (string) Str::uuid();
        $this->pay($order['id'], 200000, $ref)->assertCreated();

        // sudah lunas, tapi coba-ulang kunci yang SAMA dijawab sebagai replay, bukan 409
        $this->pay($order['id'], 200000, $ref)->assertOk()->assertJsonPath('payment_summary.status', 'fully_paid');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_client_ref_used_on_another_transaction_is_refused(): void
    {
        $a = $this->order();
        $b = $this->order();
        $ref = (string) Str::uuid();
        $this->pay($a['id'], 50000, $ref)->assertCreated();

        $this->pay($b['id'], 50000, $ref)->assertStatus(422)->assertJsonValidationErrors('client_ref');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_the_unique_index_is_the_database_backstop_for_a_duplicate_client_ref(): void
    {
        $order = $this->order();
        $ref = (string) Str::uuid();
        $row = ['preorder_id' => $order['id'], 'method' => 'cash', 'purpose' => 'full', 'amount' => 1000, 'verification' => 'verified', 'paid_at' => now(), 'client_ref' => $ref];
        Payment::create($row);

        $this->expectException(QueryException::class);
        Payment::create($row);
    }

    public function test_requests_without_a_client_ref_still_work_and_are_not_deduplicated(): void
    {
        $order = $this->order();

        $this->pay($order['id'], 50000)->assertCreated();
        $this->pay($order['id'], 50000)->assertCreated();

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_a_malformed_client_ref_is_refused(): void
    {
        $order = $this->order();

        $this->pay($order['id'], 50000, 'not-a-uuid')->assertStatus(422)->assertJsonValidationErrors('client_ref');
    }

    // --- kelebihan bayar -------------------------------------------------------------

    public function test_an_amount_above_the_remaining_balance_is_refused_with_the_maximum(): void
    {
        $order = $this->order();
        $this->pay($order['id'], 50000)->assertCreated();

        $response = $this->pay($order['id'], 150001)->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertStringContainsString('150.000', $response->json('errors.amount.0'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_paying_exactly_the_remaining_balance_is_accepted(): void
    {
        $order = $this->order();
        $this->pay($order['id'], 50000)->assertCreated();

        $this->pay($order['id'], 150000)->assertCreated()->assertJsonPath('payment_summary.status', 'fully_paid');
    }

    public function test_a_payment_on_a_fully_paid_transaction_is_refused_with_409(): void
    {
        $order = $this->order();
        $this->pay($order['id'], 200000)->assertCreated();

        $this->pay($order['id'], 1)->assertStatus(409);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_payment_on_a_closed_transaction_is_refused_with_409(): void
    {
        foreach (['handed_over', 'cancelled'] as $status) {
            $order = $this->order();
            Preorder::whereKey($order['id'])->update(['status' => $status]);

            $this->pay($order['id'], 1000)->assertStatus(409);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_second_payment_that_would_overshoot_the_balance_left_by_the_first_is_refused_with_the_fresh_remaining(): void
    {
        $order = $this->order();
        $this->pay($order['id'], 150000)->assertCreated(); // sisa 50.000

        $response = $this->pay($order['id'], 100000)->assertStatus(422);

        $this->assertStringContainsString('50.000', $response->json('errors.amount.0'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertEquals(150000, Preorder::findOrFail($order['id'])->paid_amount);
    }
}
