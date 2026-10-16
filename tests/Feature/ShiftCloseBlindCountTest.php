<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CashierSession;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 048 / T045 — tutup shift buta (SC-007) dan X-report.
 *
 * Kasir menghitung uang per pecahan tanpa melihat angka seharusnya; server yang menghitung jumlahnya,
 * membandingkan dengan kas seharusnya, dan menolak selisih di atas toleransi (409 override_required).
 * Setiap skenario di bawah dihitung TANGAN: kolom `expected` adalah angka tertulis, bukan hasil kode.
 * Harga satu varian Rp 100.000, satu unit per penjualan, toleransi bawaan Rp 5.000.
 */
class ShiftCloseBlindCountTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');

        $product = Product::factory()->create([
            'home_outlet_id' => Outlet::factory()->create()->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'BLND0001', 'sell_price' => 100000, 'cost_price' => 40000, 'current_stock' => 100,
        ]);
    }

    /** Pecahan paling sedikit lembar untuk jumlah kelipatan 100 (greedy; cukup untuk pecahan rupiah). */
    private function denominations(int $amount): array
    {
        $rows = [];
        $left = $amount;
        foreach ([100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100] as $d) {
            if ($left >= $d) {
                $qty = intdiv($left, $d);
                $rows[] = ['denomination' => $d, 'qty' => $qty];
                $left -= $qty * $d;
            }
        }

        return $rows;
    }

    private function sell(CashierSession $shift, float $tendered): void
    {
        $this->postJson('/api/v1/orders', [
            'session_id' => $shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => $tendered]],
        ])->assertCreated();
    }

    /**
     * [kas awal, penjualan (uang yang diserahkan per penjualan), kas masuk, kas keluar, hitungan fisik,
     *  kas seharusnya (dihitung tangan), apakah tutup diterima]
     *
     * @return array<string, array{0: int, 1: array<int, float>, 2: int, 3: int, 4: int, 5: int, 6: bool}>
     */
    public static function scenarios(): array
    {
        return [
            'modal saja, dihitung pas' => [150000, [], 0, 0, 150000, 150000, true],
            'satu penjualan uang pas' => [150000, [100000], 0, 0, 250000, 250000, true],
            'satu penjualan dengan kembalian 50.000' => [150000, [150000], 0, 0, 250000, 250000, true],
            'kas masuk 100.000' => [150000, [100000], 100000, 0, 350000, 350000, true],
            'kas keluar 50.000' => [150000, [100000], 0, 50000, 200000, 200000, true],
            'dua penjualan, satu kembalian 100.000' => [150000, [100000, 200000], 0, 0, 350000, 350000, true],
            'selisih kurang tepat di toleransi (5.000)' => [150000, [100000], 0, 0, 245000, 250000, true],
            'selisih kurang 5.100, di atas toleransi' => [150000, [100000], 0, 0, 244900, 250000, false],
            'selisih lebih 5.100, di atas toleransi' => [150000, [100000], 0, 0, 255100, 250000, false],
            'tanpa modal dan tanpa transaksi' => [0, [], 0, 0, 0, 0, true],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_blind_count_matches_the_hand_computed_expectation(int $opening, array $sales, int $in, int $out, int $counted, int $expected, bool $accepted): void
    {
        $shift = CashierSession::factory()->create([
            'user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => $opening,
        ]);
        foreach ($sales as $tendered) {
            $this->sell($shift, $tendered);
        }
        if ($in > 0) {
            $this->postJson("/api/v1/sessions/{$shift->id}/cash-movements", ['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => $in], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        }
        if ($out > 0) {
            $this->postJson("/api/v1/sessions/{$shift->id}/cash-movements", ['direction' => 'out', 'reason_code' => 'petty_cash', 'amount' => $out], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        }

        $response = $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => $this->denominations($counted)]);

        if ($accepted) {
            $response->assertOk()->assertJsonPath('status', 'closed');
            $this->assertEqualsWithDelta($expected, (float) $response->json('expected_cash'), 0.001);
            $this->assertEqualsWithDelta($counted - $expected, (float) $response->json('cash_difference'), 0.001);
            $this->assertEqualsWithDelta($counted, (float) $response->json('counted_total'), 0.001);
            $this->assertEqualsWithDelta($expected, (float) $response->json('z_report.expected_cash'), 0.001);
            $this->assertNotNull($response->json('z_report.closed_at'));
        } else {
            $response->assertStatus(409)->assertJsonPath('code', 'override_required');
            $this->assertSame('open', $shift->fresh()->status, 'selisih di atas toleransi tidak boleh menutup shift');
            $this->assertNull($shift->fresh()->closed_at);
        }
    }

    public function test_the_count_is_summed_on_the_server_from_denominations(): void
    {
        // Modal 158.000 = hitungan 3×50.000 + 4×2.000, jadi selisihnya nol dan tutup diterima.
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 158000]);

        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => [
            ['denomination' => 50000, 'qty' => 3], ['denomination' => 2000, 'qty' => 4],
        ]])->assertOk()->assertJsonPath('counted_total', '158000.00')->assertJsonPath('cash_difference', '0.00');
    }

    public function test_a_duplicate_denomination_in_the_count_is_refused(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 0]);

        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => [
            ['denomination' => 50000, 'qty' => 1], ['denomination' => 50000, 'qty' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('counted_by_denomination');
        $this->assertSame('open', $shift->fresh()->status);
    }

    public function test_a_pending_qris_payment_blocks_the_close(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 0]);
        $channel = \App\Models\PaymentChannel::factory()->create();
        $this->postJson('/api/v1/orders', [
            'session_id' => $shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => 'qr_ewallet', 'amount' => 100000, 'channel_id' => $channel->id]],
        ])->assertCreated();

        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => $this->denominations(0)])
            ->assertStatus(409)->assertJsonPath('code', 'pending_payments');
        $this->assertSame('open', $shift->fresh()->status);
    }

    public function test_the_legacy_close_with_a_single_figure_is_unchanged(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 100000]);

        // Tanpa hitungan pecahan, selisih 5.100 dan QRIS tertunda TIDAK diperiksa (SPA web).
        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['closing_cash' => 94900])
            ->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_difference', '-5100.00');
    }

    public function test_the_x_report_reports_the_shift_without_closing_it(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 150000]);
        $this->sell($shift, 150000);

        $this->getJson("/api/v1/sessions/{$shift->id}/x-report")
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('order_count', 1)
            ->assertJsonPath('total_sales', '100000.00')
            ->assertJsonPath('cash_received', '150000.00')
            ->assertJsonPath('change_given', '50000.00')
            ->assertJsonMissingPath('expected_cash')
            ->assertJsonPath('hourly.0.order_count', 1);

        $this->assertSame('open', $shift->fresh()->status);
    }

    public function test_closing_needs_either_a_closing_cash_or_a_count(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 0]);

        $this->postJson("/api/v1/sessions/{$shift->id}/close", [])->assertStatus(422)->assertJsonValidationErrors('closing_cash');
        $this->assertSame('open', $shift->fresh()->status);
    }

    public function test_the_cashier_never_sees_the_expected_cash_before_the_blind_count(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 150000]);
        $this->sell($shift, 100000);

        // Sebelum hitungan dikirim, kas seharusnya TIDAK boleh ada di ringkasan maupun X-report.
        $this->getJson("/api/v1/sessions/{$shift->id}/summary")->assertOk()->assertJsonMissingPath('expected_cash');
        $this->getJson("/api/v1/sessions/{$shift->id}/x-report")->assertOk()->assertJsonMissingPath('expected_cash');

        // Setelah hitungan dikirim, respons tutup memperlihatkan selisih dan kas seharusnya.
        $this->postJson("/api/v1/sessions/{$shift->id}/close", ['counted_by_denomination' => $this->denominations(250000)])
            ->assertOk()->assertJsonPath('expected_cash', '250000.00')->assertJsonPath('cash_difference', '0.00');
    }

    public function test_a_retried_close_with_the_same_key_returns_the_stored_result(): void
    {
        $shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 0]);
        $key = (string) Str::uuid();
        $body = ['counted_by_denomination' => []];

        // Respons pertama hilang di jaringan; aplikasi mengirim ulang dengan kunci yang sama.
        $first = $this->postJson("/api/v1/sessions/{$shift->id}/close", $body, ['Idempotency-Key' => $key])->assertOk();
        $second = $this->postJson("/api/v1/sessions/{$shift->id}/close", $body, ['Idempotency-Key' => $key])->assertOk();

        $this->assertSame($first->json('closed_at'), $second->json('closed_at'));
        $this->assertSame('closed', $shift->fresh()->status);
    }
}
