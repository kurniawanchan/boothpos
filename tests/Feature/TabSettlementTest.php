<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US2 — pembayaran tab: sebagian, penuh, kembalian, idempotensi
 * (FR-008, FR-009, FR-010). Memakai PaymentRecorder yang TIDAK diubah.
 */
class TabSettlementTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private \App\Models\CashierSession $session;
    private DiningTable $table;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
        $this->table = DiningTable::factory()->create(['name' => '7']);
        $this->tab = $this->apiOpenTab($this->session->id, $this->table->id)->json('id');
        // total tab = 2 x 25.000 = 50.000
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 20, price: 25000)->id, 2)->assertCreated();
    }

    public function test_a_partial_payment_keeps_the_tab_open_and_shows_the_balance(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('paid_amount', '20000.00')
            ->assertJsonPath('balance', '30000.00');

        $this->assertDatabaseHas('payments', ['order_id' => $this->tab, 'method' => 'cash', 'purpose' => 'down_payment']);
        // Meja masih terisi.
        $this->apiOpenTab($this->session->id, $this->table->id)->assertStatus(409);
    }

    public function test_paying_the_rest_completes_the_tab_computes_change_and_frees_the_table(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])->assertOk();

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 40000]])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('paid_amount', '60000.00')
            ->assertJsonPath('change_amount', '10000.00')
            ->assertJsonPath('balance', '0.00');

        $this->apiOpenTab($this->session->id, $this->table->id)->assertCreated(); // meja kosong lagi
    }

    public function test_a_full_split_payment_with_cash_and_a_proofed_transfer_completes_in_one_call(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);

        $this->apiPay($this->tab, [
            ['method' => 'cash', 'amount' => 30000],
            ['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 20000, 'proof_token' => $this->fakeProofToken()],
        ])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('change_amount', '0.00');

        $this->assertSame(2, Payment::where('order_id', $this->tab)->count());
        $this->assertDatabaseHas('payments', ['order_id' => $this->tab, 'purpose' => 'full', 'method' => 'qr_ewallet']);
    }

    // 031-optional-payment-proof — bukti bayar tidak lagi wajib untuk penjualan: pembayaran
    // non-tunai tanpa bukti dicatat, dan konfirmasinya boleh menyusul dari detail transaksi.
    // (Versi awal tes ini menuntut penolakan 409 tanpa bukti; aturan itu sudah dicabut 031.)
    public function test_a_non_cash_payment_without_a_proof_is_recorded(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);

        $this->apiPay($this->tab, [
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 40000],
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame(2, Payment::where('order_id', $this->tab)->count());
    }

    // Yang tetap berlaku sesudah 031: non-tunai wajib punya kanal; kalau tidak, seluruh pengiriman
    // ditolak dan tidak ada baris yang tercatat (termasuk baris tunai di depannya).
    public function test_a_non_cash_payment_without_a_channel_is_refused_and_records_nothing(): void
    {
        $this->apiPay($this->tab, [
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'qr_ewallet', 'amount' => 40000],
        ])->assertStatus(409); // endpoint tab memetakan aturan bisnis ke 409 (HandlesTabErrors)

        $this->assertSame(0, Payment::where('order_id', $this->tab)->count());
        $this->assertSame('0.00', Order::find($this->tab)->paid_amount);
    }

    public function test_change_may_only_come_from_cash(): void
    {
        $channel = PaymentChannel::factory()->create(['type' => 'qr_ewallet']);

        // 60.000 non-tunai untuk tagihan 50.000: kembalian 10.000 tanpa uang tunai.
        $this->apiPay($this->tab, [
            ['method' => 'qr_ewallet', 'channel_id' => $channel->id, 'amount' => 60000, 'proof_token' => $this->fakeProofToken()],
        ])->assertStatus(409);

        $this->assertSame('open', Order::find($this->tab)->status);
        $this->assertSame(0, Payment::where('order_id', $this->tab)->count());
    }

    public function test_repeating_a_payment_submission_records_it_only_once(): void
    {
        $ref = $this->newLocalRef();

        $first = $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]], $ref)->assertOk();
        $again = $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]], $ref)->assertOk();

        $this->assertSame('completed', $first->json('status'));
        $this->assertSame('completed', $again->json('status'));
        $this->assertSame(1, Payment::where('order_id', $this->tab)->count());
    }

    public function test_a_repeated_partial_submission_does_not_double_count(): void
    {
        $ref = $this->newLocalRef();

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]], $ref)->assertOk();
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]], $ref)->assertOk()->assertJsonPath('paid_amount', '20000.00');

        $this->assertSame(1, Payment::where('order_id', $this->tab)->count());
    }

    public function test_paying_an_already_completed_tab_with_a_new_reference_is_a_conflict(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]])->assertOk();

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]])->assertStatus(409);
        $this->assertSame(1, Payment::where('order_id', $this->tab)->count());
    }

    public function test_an_order_level_discount_given_at_payment_lowers_the_total(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 45000]], null, ['discount_amount' => 5000])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('discount_amount', '5000.00')
            ->assertJsonPath('total_amount', '45000.00');
    }

    public function test_a_discount_larger_than_the_subtotal_is_refused(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 1000]], null, ['discount_amount' => 60000])->assertStatus(409);
        $this->assertEquals(0, Order::find($this->tab)->discount_amount);
    }

    public function test_removing_a_line_that_would_drop_the_total_below_what_was_paid_is_refused(): void
    {
        $second = $this->apiAddLine($this->tab, $this->makeVariant(price: 25000)->id)->json('lines.1.id'); // total 75.000
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 60000]])->assertOk()->assertJsonPath('status', 'open');

        $this->deleteJson("/api/v1/tabs/{$this->tab}/items/{$second}")->assertStatus(409); // total jadi 50.000 < dibayar 60.000
        $this->assertNotNull(OrderItem::find($second));
    }

    public function test_an_empty_tab_cannot_be_paid(): void
    {
        $empty = $this->apiOpenTab($this->session->id)->json('id');

        $this->apiPay($empty, [['method' => 'cash', 'amount' => 1000]])->assertStatus(409);
    }

    public function test_payment_needs_an_open_shift(): void
    {
        $this->session->update(['status' => 'closed', 'closed_at' => now()]);

        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]])->assertStatus(409);
        $this->assertSame(0, Payment::where('order_id', $this->tab)->count());
    }

    public function test_the_payload_validates_amounts_and_the_client_reference(): void
    {
        $this->postJson("/api/v1/tabs/{$this->tab}/payments", ['payments' => [['method' => 'cash', 'amount' => 1000]]])
            ->assertStatus(422)->assertJsonValidationErrors(['client_ref']);
        $this->postJson("/api/v1/tabs/{$this->tab}/payments", ['client_ref' => $this->newLocalRef(), 'payments' => [['method' => 'cash', 'amount' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors(['payments.0.amount']);
        $this->postJson("/api/v1/tabs/{$this->tab}/payments", ['client_ref' => $this->newLocalRef(), 'payments' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['payments']);
    }

    public function test_the_completed_tab_has_a_receipt_with_its_table_and_a_normal_sale_total(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]])->assertOk();

        $this->getJson("/api/v1/orders/{$this->tab}/receipt")
            ->assertOk()
            ->assertJsonPath('service_type', 'dine_in')
            ->assertJsonPath('table.name', '7')
            ->assertJsonPath('total_amount', '50000.00');
    }

    public function test_a_completed_tab_appears_in_the_order_list_like_any_sale(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 50000]])->assertOk();

        $ids = collect($this->getJson('/api/v1/orders')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->tab));
    }
}
