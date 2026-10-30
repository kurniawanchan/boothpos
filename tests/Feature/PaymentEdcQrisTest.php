<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / S3 — metode EDC dan aturan non-tunai: kode approval EDC (tanpa nomor kartu), deteksi ganda dalam
 * jendela `edc_duplicate_window_seconds`, QRIS tertunda memblokir non-tunai lain (cash tetap boleh), dan
 * non-tunai tidak boleh melebihi sisa tagihan.
 */
class PaymentEdcQrisTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private CashierSession $shift;
    private int $channelId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier);
        $this->channelId = PaymentChannel::factory()->create()->id;
    }

    /** Tab dengan total Rp 20.000 (dua porsi Rp 10.000). */
    private function tab(): int
    {
        $tab = $this->apiOpenTab($this->shift->id)->json('id');
        $this->apiAddLine($tab, $this->makeVariant(stock: 10, price: 10000)->id, 2)->assertCreated();

        return $tab;
    }

    private function edc(int $tab, string $code, float $amount = 10000, array $extra = [])
    {
        return $this->apiPay($tab, [['method' => 'edc', 'amount' => $amount, 'channel_id' => $this->channelId, 'approval_code' => $code] + $extra]);
    }

    public function test_an_edc_approval_code_must_be_six_to_twelve_uppercase_letters_or_digits(): void
    {
        $tab = $this->tab();

        $this->edc($tab, 'ab12cd')->assertStatus(422);
        $this->edc($tab, 'AB12')->assertStatus(422);
        $this->edc($tab, 'AB-12CD')->assertStatus(422);
        $this->assertSame(0, Payment::where('method', 'edc')->count());
    }

    public function test_an_edc_payment_stores_the_approval_code_and_never_a_card_number(): void
    {
        $tab = $this->tab();

        $this->edc($tab, 'AB12CD', 20000, ['card_number' => '4111111111111111'])->assertOk()->assertJsonPath('status', 'completed');

        $payment = Payment::where('method', 'edc')->firstOrFail();
        $this->assertSame('AB12CD', $payment->approval_code);
        $this->assertNotContains('4111111111111111', $payment->getAttributes());
    }

    public function test_the_same_approval_code_within_the_window_is_a_duplicate_unless_confirmed(): void
    {
        $this->edc($this->tab(), 'XY98ZT')->assertOk();

        $second = $this->tab();
        $this->edc($second, 'XY98ZT')->assertStatus(409)->assertJsonPath('code', 'duplicate_edc');
        $this->edc($second, 'XY98ZT', 10000, ['confirm_duplicate' => true])->assertOk();
    }

    public function test_the_same_code_after_the_window_is_not_a_duplicate(): void
    {
        $this->edc($this->tab(), 'XY98ZT')->assertOk();
        $this->travel(121)->seconds();

        $this->edc($this->tab(), 'XY98ZT')->assertOk();
    }

    public function test_a_pending_qris_payment_blocks_other_non_cash_payments_but_not_cash(): void
    {
        $tab = $this->tab();
        $this->apiPay($tab, [['method' => 'qr_ewallet', 'amount' => 5000, 'channel_id' => $this->channelId]])->assertOk()->assertJsonPath('status', 'open');
        // 084 — QRIS baru langsung terverifikasi; aturan ini hanya berlaku untuk baris WARISAN yang masih `pending`.
        \App\Models\Payment::where('order_id', $tab)->where('method', 'qr_ewallet')->update(['verification' => 'pending']);

        $this->edc($tab, 'AB12CD', 5000)->assertStatus(409)->assertJsonPath('code', 'pending_qris');
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 5000]])->assertOk();
    }

    public function test_non_cash_payments_may_not_exceed_the_remaining_balance(): void
    {
        $tab = $this->tab();

        // Aturan bisnis tab (409), bukan galat format: sisa tagihan Rp 20.000.
        $this->edc($tab, 'AB12CD', 30000)->assertStatus(409);
        $this->assertSame(0, Payment::where('method', 'edc')->count());
        $this->assertSame('open', Order::find($tab)->status);
    }
}
