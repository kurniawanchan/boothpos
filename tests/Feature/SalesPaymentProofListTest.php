<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 056 (US2) — baris daftar Sales membawa `payment_proof_count`: jumlah pembayaran NON-TUNAI penjualan
 * itu yang bukti BERLAKU-nya boleh dilihat pengguna yang meminta. Izin dihitung server lewat
 * Payment::proofViewableBy() (satu-satunya definisi); daftar tak pernah membawa id/URL/gambar bukti,
 * dan `payments.proofs` dimuat sekali (bukan per baris).
 */
class SalesPaymentProofListTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashierA;

    private User $cashierB;

    private Event $event;

    private CashierSession $session;

    private $variant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->cashierA = User::factory()->create(['role' => 'cashier']);
        $this->cashierB = User::factory()->create(['role' => 'cashier']);
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashierA->id, 'status' => 'open']);

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create(['sku' => 'SPPL0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 500]);
        $this->customer = Customer::factory()->create();
    }

    /** Penjualan lunas tunai; pembayaran non-tunai ditambahkan terpisah oleh tiap tes. */
    private function sale(): Order
    {
        return app(OrderService::class)->create([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(), 'customer_id' => $this->customer->id,
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ], $this->cashierA);
    }

    /** chk_payments_channel: non-tunai wajib punya kanal, tunai wajib tanpa kanal. */
    private function pay(Order $order, string $method = 'qr_ewallet', ?User $recorder = null): Payment
    {
        $channel = $method === 'cash' ? null : PaymentChannel::create([
            'type' => $method, 'provider' => 'Shopee', 'account_name' => 'Test', 'is_active' => true,
        ])->id;

        return Payment::create([
            'order_id' => $order->id, 'method' => $method, 'purpose' => 'settlement', 'amount' => 1000,
            'verification' => 'pending', 'paid_at' => now(), 'channel_id' => $channel, 'recorded_by' => $recorder?->id,
        ]);
    }

    private function proof(Payment $payment, ?User $uploader = null, array $overrides = []): PaymentProof
    {
        return PaymentProof::create(array_merge([
            'proof_token' => (string) Str::uuid(), 'payment_id' => $payment->id,
            'file_path' => 'payment-proofs/'.uniqid().'.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10,
            'captured_via' => 'upload', 'uploaded_by' => $uploader?->id, 'created_at' => now(),
        ], $overrides));
    }

    private function rowFor(Order $order, string $query = ''): array
    {
        $report = $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}{$query}")->assertOk()->json();

        return collect($report['transactions'])->firstWhere('id', $order->id);
    }

    public function test_every_row_carries_an_integer_count_and_a_sale_without_proofs_is_zero(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $order = $this->sale();

        $row = $this->rowFor($order);

        $this->assertSame(0, $row['payment_proof_count']);
    }

    public function test_owner_counts_every_non_cash_payment_with_a_current_proof(): void
    {
        $order = $this->sale();
        $this->proof($this->pay($order, 'qr_ewallet', $this->cashierA), $this->cashierA);
        $this->proof($this->pay($order, 'bank_transfer', $this->cashierA), $this->cashierA);
        $this->pay($order, 'qr_ewallet', $this->cashierA); // tanpa bukti → tidak dihitung

        $this->actingAs($this->owner, 'sanctum');

        $this->assertSame(2, $this->rowFor($order)['payment_proof_count']);
    }

    public function test_the_recording_cashier_sees_the_proof_but_an_unrelated_cashier_does_not(): void
    {
        $order = $this->sale();
        $this->proof($this->pay($order, 'qr_ewallet', $this->cashierA), $this->owner); // diunggah owner, dicatat kasir A

        $this->actingAs($this->cashierA, 'sanctum');
        $this->assertSame(1, $this->rowFor($order)['payment_proof_count']);

        $this->actingAs($this->cashierB, 'sanctum');
        $this->assertSame(0, $this->rowFor($order)['payment_proof_count'], 'kasir lain tak boleh tahu ada bukti yang tak bisa ia buka');
    }

    public function test_a_replaced_proof_counts_once_and_a_fully_superseded_payment_counts_zero(): void
    {
        $order = $this->sale();
        $replaced = $this->pay($order, 'qr_ewallet', $this->cashierA);
        $this->proof($replaced, $this->cashierA, ['superseded_at' => now()->subHour()]);
        $this->proof($replaced, $this->cashierA);
        $gone = $this->pay($order, 'bank_transfer', $this->cashierA);
        $this->proof($gone, $this->cashierA, ['superseded_at' => now()]);

        $this->actingAs($this->owner, 'sanctum');

        $this->assertSame(1, $this->rowFor($order)['payment_proof_count']);
    }

    public function test_cash_payments_are_never_counted_even_if_a_proof_row_exists(): void
    {
        $order = $this->sale();
        $this->proof($this->pay($order, 'cash', $this->cashierA), $this->cashierA);

        $this->actingAs($this->owner, 'sanctum');

        $this->assertSame(0, $this->rowFor($order)['payment_proof_count']);
    }

    public function test_a_voided_sale_still_reports_its_proofs_for_audit(): void
    {
        $order = $this->sale();
        $this->proof($this->pay($order, 'qr_ewallet', $this->cashierA), $this->cashierA);
        $order->forceFill(['status' => 'voided'])->save();

        $this->actingAs($this->owner, 'sanctum');

        $this->assertSame(1, $this->rowFor($order, '&include_voided=1')['payment_proof_count']);
    }

    public function test_the_row_never_leaks_proof_ids_or_urls(): void
    {
        $order = $this->sale();
        $this->proof($this->pay($order, 'qr_ewallet', $this->cashierA), $this->cashierA);
        $this->actingAs($this->owner, 'sanctum');

        $json = json_encode($this->rowFor($order));

        $this->assertStringNotContainsString('payment-proofs', $json);
        $this->assertArrayNotHasKey('payments', $this->rowFor($order));
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_sales(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $first = $this->sale();
        $this->proof($this->pay($first, 'qr_ewallet', $this->cashierA), $this->cashierA);
        $this->rowFor($first); // pemanasan: cache setting/lisensi terisi pada panggilan pertama

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/reports/sales?event_id={$this->event->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();

        for ($i = 0; $i < 6; $i++) {
            $order = $this->sale();
            $this->proof($this->pay($order, 'bank_transfer', $this->cashierA), $this->cashierA);
        }

        $this->assertSame($few, $count(), 'payments.proofs harus di-eager-load, bukan dimuat per baris');
    }
}
