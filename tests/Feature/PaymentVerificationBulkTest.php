<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 032-mark-payment-verified (US3) — POST /orders/verify-payments: verifikasi pembayaran non-tunai
 * yang masih pending dari BANYAK penjualan sekaligus (pilihan di daftar Sales). Aksi massal hanyalah
 * perulangan atas jalur satu-pembayaran: yang tak bisa diverifikasi pengguna itu DILEWATI (dengan
 * alasan), tak menggagalkan sisanya, dan tak ada uang/status/total yang berubah.
 */
class PaymentVerificationBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $cashierA;

    private User $cashierB;

    private ProductVariant $variant;

    private PaymentChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashierA = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir A']);
        $this->cashierB = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir B']);
        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create(['sku' => 'VBK0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 500]);
        $this->channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
    }

    private function as(User $user): static
    {
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    /** @return array{order: array, payment_ids: array<int,int>} */
    private function sale(User $cashier, ?array $payments = null): array
    {
        $session = CashierSession::factory()->create([
            'event_id' => Event::factory()->create(['status' => 'active'])->id, 'user_id' => $cashier->id, 'status' => 'open',
        ]);
        $this->as($cashier);
        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
            'payments' => $payments ?? [['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 50000]],
        ])->assertCreated()->json();

        return ['order' => $order, 'payment_ids' => collect($order['payments'])->pluck('id')->all()];
    }

    private function bulk(array $orders, ?User $as = null)
    {
        if ($as) {
            $this->as($as);
        }

        return $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => collect($orders)->map(fn ($s) => is_array($s) ? $s['order']['id'] : $s)->all()]);
    }

    /** Skenario campuran: 3 pembayaran bisa diverifikasi + 1 sudah, 1 batal, 1 ditolak, 1 milik pemanggil, 1 tunai. */
    private function mixedSelection(): array
    {
        $split = [
            ['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 20000],
            ['method' => 'qr_ewallet', 'channel_id' => $this->channel->id, 'amount' => 30000],
        ];
        $s = [
            'ok1' => $this->sale($this->cashierA),
            'split' => $this->sale($this->cashierA, $split),
            'cash' => $this->sale($this->cashierA, [['method' => 'cash', 'amount' => 50000]]),
            'voided' => $this->sale($this->cashierA),
            'already' => $this->sale($this->cashierA),
            'rejected' => $this->sale($this->cashierA),
            'own' => $this->sale($this->cashierB),
        ];
        Order::whereKey($s['voided']['order']['id'])->update(['status' => 'voided']);
        Payment::whereKey($s['already']['payment_ids'][0])->update(['verification' => 'verified', 'verified_by' => $this->cashierA->id, 'verified_at' => now()]);
        Payment::whereKey($s['rejected']['payment_ids'][0])->update(['verification' => 'rejected']);

        return $s;
    }

    public function test_a_mixed_selection_is_summarised_and_only_the_right_rows_change(): void
    {
        $s = $this->mixedSelection();

        $response = $this->bulk($s, $this->cashierB)->assertOk();

        $response->assertJson([
            'verified' => 3,
            'verified_orders' => 2,
            'skipped' => ['already_verified' => 1, 'own_payment' => 1, 'voided' => 1, 'rejected' => 1],
            'skipped_total' => 4,
        ]);
        foreach ([$s['ok1']['payment_ids'][0], ...$s['split']['payment_ids']] as $id) {
            $p = Payment::find($id);
            $this->assertSame('verified', $p->verification);
            $this->assertSame($this->cashierB->id, (int) $p->verified_by);
        }
        $this->assertSame('verified', Payment::find($s['cash']['payment_ids'][0])->verification);
        $this->assertNull(Payment::find($s['cash']['payment_ids'][0])->verified_by);                  // tunai: tak disentuh
        $this->assertSame('pending', Payment::find($s['voided']['payment_ids'][0])->verification);
        $this->assertSame($this->cashierA->id, (int) Payment::find($s['already']['payment_ids'][0])->verified_by); // verifier asli utuh
        $this->assertSame('rejected', Payment::find($s['rejected']['payment_ids'][0])->verification);
        $this->assertSame('pending', Payment::find($s['own']['payment_ids'][0])->verification);       // milik pemanggil
        $this->assertSame(3, ActivityLog::where('action', 'payment_verified')->count());
    }

    public function test_the_same_selection_as_an_owner_also_verifies_the_callers_own_payments(): void
    {
        $s = $this->mixedSelection();
        $owner = User::factory()->create(['role' => 'owner']);

        $response = $this->bulk($s, $owner)->assertOk();

        $response->assertJson(['verified' => 4, 'skipped' => ['already_verified' => 1, 'own_payment' => 0, 'voided' => 1, 'rejected' => 1]]);
        $this->assertSame('verified', Payment::find($s['own']['payment_ids'][0])->verification);
    }

    public function test_a_cashier_never_verifies_their_own_sales_in_bulk(): void
    {
        $mine = [$this->sale($this->cashierB), $this->sale($this->cashierB)];

        $response = $this->bulk($mine, $this->cashierB)->assertOk();

        $response->assertJson(['verified' => 0, 'verified_orders' => 0, 'skipped' => ['own_payment' => 2], 'skipped_total' => 2]);
        $this->assertSame(0, ActivityLog::where('action', 'payment_verified')->count());
    }

    public function test_running_it_twice_verifies_once_and_counts_the_second_run_as_already_verified(): void
    {
        $s = [$this->sale($this->cashierA), $this->sale($this->cashierA)];

        $this->bulk($s, $this->cashierB)->assertOk()->assertJson(['verified' => 2]);
        $this->bulk($s)->assertOk()->assertJson(['verified' => 0, 'skipped' => ['already_verified' => 2], 'skipped_total' => 2]);

        $this->assertSame(2, ActivityLog::where('action', 'payment_verified')->count());
    }

    public function test_unknown_and_other_mode_ids_are_ignored_without_error(): void
    {
        $s = $this->sale($this->cashierA);
        $other = $this->sale($this->cashierA);
        DB::table('orders')->where('id', $other['order']['id'])->update(['data_mode' => 'demo']);
        DB::table('payments')->where('id', $other['payment_ids'][0])->update(['data_mode' => 'demo']);

        $response = $this->bulk([$s['order']['id'], $other['order']['id'], 999999], $this->cashierB)->assertOk();

        $response->assertJson(['verified' => 1, 'verified_orders' => 1, 'skipped_total' => 0]);
        $this->assertSame('pending', DB::table('payments')->where('id', $other['payment_ids'][0])->value('verification'));
    }

    public function test_a_selection_with_nothing_to_verify_answers_zero_without_error(): void
    {
        $cashOnly = $this->sale($this->cashierA, [['method' => 'cash', 'amount' => 50000]]);

        $this->bulk([$cashOnly], $this->cashierB)->assertOk()->assertJson(['verified' => 0, 'verified_orders' => 0, 'skipped_total' => 0]);
    }

    public function test_the_request_shape_is_validated(): void
    {
        $this->as($this->cashierB);

        $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => []])->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->postJson('/api/v1/orders/verify-payments', [])->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => range(1, 201)])->assertStatus(422)->assertJsonValidationErrors('order_ids');
        $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => ['abc']])->assertStatus(422);
        $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => [5, 5]])->assertStatus(422);
        $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => range(1, 200)])->assertOk();
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/orders/verify-payments', ['order_ids' => [1]])->assertUnauthorized();
    }

    public function test_bulk_verification_changes_no_money_status_or_totals_and_clears_the_sales_badge(): void
    {
        $s = $this->sale($this->cashierA);
        $order = Order::findOrFail($s['order']['id']);
        $before = $order->only(['total_amount', 'paid_amount', 'change_amount', 'status', 'subtotal']);
        $paymentBefore = Payment::find($s['payment_ids'][0])->only(['amount', 'method', 'channel_id', 'paid_at', 'session_id']);

        $this->bulk([$s], $this->cashierB)->assertOk();

        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertEquals($paymentBefore, Payment::find($s['payment_ids'][0])->only(array_keys($paymentBefore)));
        $row = collect($this->getJson('/api/v1/reports/sales')->json('transactions'))->firstWhere('id', $order->id);
        $this->assertSame('verified', $row['payment_state']);
    }
}
