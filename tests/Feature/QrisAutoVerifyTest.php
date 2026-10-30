<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 084 US1 — pembayaran QRIS di jalur tab (tablet POS) langsung terverifikasi; metode lain tidak berubah,
 * dan baris warisan yang `pending` tetap menghalangi penutupan shift sampai diverifikasi.
 */
class QrisAutoVerifyTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private \App\Models\CashierSession $session;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
        $this->tab = $this->apiOpenTab($this->session->id)->json('id');
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 10, price: 10000)->id, 2)->assertCreated();
    }

    public function test_qris_payment_is_verified_on_creation(): void
    {
        $channel = PaymentChannel::factory()->create();

        $this->apiPay($this->tab, [['method' => 'qr_ewallet', 'amount' => 5000, 'channel_id' => $channel->id]])->assertOk();

        $this->assertSame('verified', Payment::where('order_id', $this->tab)->where('method', 'qr_ewallet')->firstOrFail()->verification);
    }

    public function test_a_following_non_cash_payment_is_no_longer_blocked_by_qris(): void
    {
        $channel = PaymentChannel::factory()->create();

        $this->apiPay($this->tab, [['method' => 'qr_ewallet', 'amount' => 5000, 'channel_id' => $channel->id]])->assertOk();
        // Sebelum 084 ini 409 `pending_qris`.
        $this->apiPay($this->tab, [['method' => 'qr_ewallet', 'amount' => 5000, 'channel_id' => $channel->id]])->assertOk();
    }

    public function test_cash_stays_verified_and_the_tab_can_complete(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame('verified', Payment::where('order_id', $this->tab)->firstOrFail()->verification);
    }

    public function test_a_legacy_pending_qris_row_still_blocks_closing_the_shift(): void
    {
        $channel = PaymentChannel::factory()->create();
        // Lunas lewat QRIS supaya tab selesai (tab terbuka akan menolak penutupan lebih dulu dengan `open_tabs`).
        $this->apiPay($this->tab, [['method' => 'qr_ewallet', 'amount' => 20000, 'channel_id' => $channel->id]])
            ->assertOk()->assertJsonPath('status', 'completed');
        // Baris warisan sebelum 084: QRIS yang masih menunggu verifikasi.
        Payment::where('order_id', $this->tab)->update(['verification' => 'pending']);

        $this->postJson("/api/v1/sessions/{$this->session->id}/close", ['counted_by_denomination' => [['denomination' => 1000, 'qty' => 1]]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'pending_payments');
    }
}
