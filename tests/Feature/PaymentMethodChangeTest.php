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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 054 (US7) — koreksi METODE (dan kanal) satu pembayaran penjualan POS lewat
 * PATCH /orders/{order}/payments/{payment}/method. Dikunci di sini: siapa boleh, tiap penolakan
 * (batal, shift tertutup, kembalian, tanpa shift terbuka, kanal salah), efek pada verifikasi dan
 * shift, dan — paling penting — nominal, total, status, dan cache paid_amount tidak berubah.
 */
class PaymentMethodChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $owner;

    private ProductVariant $variant;

    private PaymentChannel $qris;

    private PaymentChannel $bank;

    private CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'MCH0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 100]);
        $this->qris = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);
        $this->bank = PaymentChannel::factory()->create(['type' => 'bank_transfer']);
        $this->session = CashierSession::factory()->create([
            'event_id' => Event::factory()->create(['status' => 'active'])->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);
    }

    private function as(User $user): static
    {
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    /** Penjualan 50.000 (2 × 25.000) milik $this->cashier dengan pembayaran $payments. */
    private function sale(array $payments): array
    {
        $this->as($this->cashier);

        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'payments' => $payments,
        ])->assertCreated()->json();
    }

    private function cashSale(int $tendered = 50000): array
    {
        return $this->sale([['method' => 'cash', 'amount' => $tendered]]);
    }

    private function qrisSale(): array
    {
        return $this->sale([['method' => 'qr_ewallet', 'channel_id' => $this->qris->id, 'amount' => 50000]]);
    }

    private function change(array $order, array $body, int $index = 0)
    {
        return $this->patchJson("/api/v1/orders/{$order['id']}/payments/{$order['payments'][$index]['id']}/method", $body);
    }

    // --- caminho utama -------------------------------------------------------------

    public function test_cash_can_be_corrected_to_a_channel_and_then_needs_verification(): void
    {
        $order = $this->cashSale();
        $before = Order::find($order['id']);

        $res = $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertOk();

        $payment = Payment::find($order['payments'][0]['id']);
        $this->assertSame('qr_ewallet', $payment->method);
        $this->assertSame($this->qris->id, $payment->channel_id);
        $this->assertSame('pending', $payment->verification);
        $this->assertNull($payment->verified_by);
        $this->assertSame('qr_ewallet', $res->json('payments.0.method'));

        // Uang tak berubah sama sekali.
        $this->assertSame('50000.00', $payment->amount);
        $after = Order::find($order['id']);
        $this->assertSame($before->total_amount, $after->total_amount);
        $this->assertSame($before->paid_amount, $after->paid_amount);
        $this->assertSame('completed', $after->status);
    }

    public function test_a_channel_payment_can_be_corrected_to_another_channel_and_a_verified_one_goes_back_to_pending(): void
    {
        $order = $this->qrisSale();
        $paymentId = $order['payments'][0]['id'];
        $this->as($this->owner)->postJson("/api/v1/orders/{$order['id']}/payments/{$paymentId}/verify")->assertOk();
        $this->assertSame('verified', Payment::find($paymentId)->verification);

        $this->change($order, ['method' => 'bank_transfer', 'channel_id' => $this->bank->id])->assertOk();

        $payment = Payment::find($paymentId);
        $this->assertSame('bank_transfer', $payment->method);
        $this->assertSame($this->bank->id, $payment->channel_id);
        $this->assertSame('pending', $payment->verification);
        $this->assertNull($payment->verified_by);
        $this->assertNull($payment->verified_at);
    }

    public function test_an_owner_can_correct_a_channel_payment_to_cash_into_their_own_open_shift(): void
    {
        $order = $this->qrisSale();
        $ownerShift = CashierSession::factory()->create(['event_id' => $this->session->event_id, 'user_id' => $this->owner->id, 'status' => 'open']);

        $this->as($this->owner)->change($order, ['method' => 'cash'])->assertOk();

        $payment = Payment::find($order['payments'][0]['id']);
        $this->assertSame('cash', $payment->method);
        $this->assertNull($payment->channel_id);
        $this->assertSame('verified', $payment->verification);
        $this->assertSame($ownerShift->id, $payment->session_id);
    }

    public function test_a_cashier_cannot_turn_a_non_cash_payment_into_cash_even_their_own(): void
    {
        $order = $this->qrisSale(); // dicatat kasir sendiri, shift kasir terbuka

        $this->as($this->cashier)->change($order, ['method' => 'cash'])->assertForbidden();

        $payment = Payment::find($order['payments'][0]['id']);
        $this->assertSame('qr_ewallet', $payment->method);
        $this->assertSame('pending', $payment->verification);
    }

    public function test_changing_to_cash_needs_an_open_shift_of_the_actor(): void
    {
        $order = $this->qrisSale(); // pemilik tidak punya shift terbuka
        $this->session->update(['status' => 'closed', 'closed_at' => now()]); // shift penjualan pun tertutup

        $this->as($this->owner)->change($order, ['method' => 'cash'])->assertStatus(409);

        $this->assertSame('qr_ewallet', Payment::find($order['payments'][0]['id'])->method);
    }

    /** 063 — Owner tanpa shift sendiri mengoreksi ke tunai lewat shift penjualan yang (dibuka kembali) terbuka. */
    public function test_an_owner_without_a_shift_can_correct_to_cash_into_the_sales_open_shift(): void
    {
        $order = $this->qrisSale();

        $this->as($this->owner)->change($order, ['method' => 'cash'])->assertOk();

        $this->assertSame($this->session->id, Payment::find($order['payments'][0]['id'])->session_id);
    }

    public function test_the_sales_open_shift_fallback_is_for_the_owner_only(): void
    {
        $order = $this->qrisSale();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->as($admin)->change($order, ['method' => 'cash'])->assertStatus(409);
    }

    public function test_the_change_is_audited_with_before_and_after(): void
    {
        $order = $this->cashSale();

        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertOk();

        $log = ActivityLog::where('action', 'payment_method_changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->cashier->id, (int) $log->user_id);
        $this->assertSame('cash', $log->old_values['method']);
        $this->assertSame('qr_ewallet', $log->new_values['method']);
    }

    // --- penolakan -----------------------------------------------------------------

    public function test_nothing_to_change_is_a_validation_error(): void
    {
        $order = $this->qrisSale();

        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertStatus(422);
    }

    public function test_a_channel_is_required_for_non_cash_and_must_match_the_method(): void
    {
        $order = $this->cashSale();

        $this->change($order, ['method' => 'qr_ewallet'])->assertStatus(422);
        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->bank->id])->assertStatus(422);
        $this->change($order, ['method' => 'cash', 'channel_id' => $this->qris->id])->assertStatus(422);

        $this->assertSame('cash', Payment::find($order['payments'][0]['id'])->method);
    }

    public function test_an_inactive_channel_is_refused(): void
    {
        $order = $this->cashSale();
        $this->qris->update(['is_active' => false]);

        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertStatus(422);
    }

    public function test_a_voided_sale_cannot_be_changed(): void
    {
        $order = $this->cashSale();
        $this->as($this->owner)->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'salah'])->assertOk();

        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertStatus(409);
    }

    public function test_cash_received_in_a_closed_shift_can_be_recategorised_without_rewriting_the_shift(): void
    {
        $order = $this->cashSale();
        $this->session->update(['status' => 'closed', 'closed_at' => now(), 'closing_cash' => 50000, 'expected_cash' => 50000]);

        $this->as($this->owner);
        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertOk();

        $this->assertSame('qr_ewallet', Payment::find($order['payments'][0]['id'])->method);
        // Angka shift tertutup adalah catatan historis: tidak ditulis ulang.
        $this->assertSame('50000.00', number_format((float) $this->session->fresh()->expected_cash, 2, '.', ''));
    }

    public function test_a_rejected_payment_cannot_be_turned_into_verified_cash(): void
    {
        $order = $this->qrisSale();
        Payment::whereKey($order['payments'][0]['id'])->update(['verification' => 'rejected']);

        $this->as($this->cashier);
        $res = $this->change($order, ['method' => 'cash'])->assertStatus(409);

        $this->assertSame('rejected', $res->json('code'));
        $this->assertSame('rejected', Payment::find($order['payments'][0]['id'])->verification);
    }

    public function test_cash_with_change_given_cannot_be_recategorised(): void
    {
        $order = $this->cashSale(60000); // kembalian 10.000

        $this->as($this->cashier);
        $res = $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertStatus(409);

        $this->assertSame('change_given', $res->json('code'));
        $this->assertSame('cash', Payment::find($order['payments'][0]['id'])->method);
    }

    // --- siapa boleh ---------------------------------------------------------------

    public function test_the_recorder_and_owner_may_change_but_another_cashier_may_not(): void
    {
        $order = $this->cashSale();
        $other = User::factory()->create(['role' => 'cashier']);

        $this->as($other);
        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertStatus(403);
        $this->assertSame('cash', Payment::find($order['payments'][0]['id'])->method);

        $this->as($this->owner);
        $this->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertOk();
    }

    public function test_the_payload_tells_the_ui_who_can_change_and_who_cannot(): void
    {
        $order = $this->cashSale();
        $withChange = $this->cashSale(60000);

        $this->as($this->cashier);
        $this->assertTrue($this->getJson("/api/v1/orders/{$order['id']}")->json('payments.0.can_change_method'));
        $this->assertFalse($this->getJson("/api/v1/orders/{$withChange['id']}")->json('payments.0.can_change_method'), 'cash with change can never succeed, so the action is hidden');

        $this->as(User::factory()->create(['role' => 'cashier']));
        $this->assertFalse($this->getJson("/api/v1/orders/{$order['id']}")->json('payments.0.can_change_method'));
    }

    // --- efek pada kas shift -------------------------------------------------------

    public function test_the_open_shifts_expected_cash_follows_the_corrected_method(): void
    {
        $order = $this->cashSale();
        $this->as($this->cashier)->change($order, ['method' => 'qr_ewallet', 'channel_id' => $this->qris->id])->assertOk();

        $summary = $this->getJson("/api/v1/sessions/{$this->session->id}/summary")->assertOk()->json();
        $cash = collect($summary['by_method'])->firstWhere('method', 'cash');

        $this->assertNull($cash, 'no verified cash is left in the shift');
    }
}
