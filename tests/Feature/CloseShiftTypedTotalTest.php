<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CashierSession;
use App\Models\Outlet;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\TestCase;

/**
 * 065 / US3 — tutup shift dengan TOTAL KETIKAN (`counted_total`): tetap di protokol terkontrol (QRIS tertunda,
 * tab terbuka, persetujuan selisih terikat ke angka yang dikirim), tanpa EDC, dan idempoten.
 * Modal 150.000 + satu penjualan tunai pas 100.000 → kas seharusnya 250.000; toleransi bawaan 5.000.
 */
class CloseShiftTypedTotalTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;

    private User $cashier;

    private CashierSession $shift;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $product = Product::factory()->create(['home_outlet_id' => Outlet::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'TYPD0001', 'sell_price' => 100000, 'cost_price' => 40000, 'current_stock' => 100]);
        $this->shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 150000]);
    }

    private function sell(string $method = 'cash', array $extra = []): void
    {
        $this->postJson('/api/v1/orders', [
            'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
            'payments' => [['method' => $method, 'amount' => 100000] + $extra],
        ])->assertCreated();
    }

    private function close(array $body, ?string $key = null)
    {
        return $this->postJson("/api/v1/sessions/{$this->shift->id}/close", $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_a_typed_total_closes_the_shift_and_is_stored_as_the_count(): void
    {
        $this->sell();

        $res = $this->close(['counted_total' => 250000])->assertOk();

        $res->assertJsonPath('status', 'closed')->assertJsonPath('closing_cash', '250000.00')->assertJsonPath('expected_cash', '250000.00')->assertJsonPath('cash_difference', '0.00');
        $s = $this->shift->fresh();
        $this->assertNull($s->counted_by_denomination, 'total ketikan tidak punya rincian pecahan');
        $this->assertSame('250000.00', (string) $s->counted_total);
        $this->assertSame('0.00', (string) $s->variance);
        $this->assertSame('250000.00', $s->z_report['counted_total']);
        $this->assertArrayNotHasKey('edc_batch_total', $s->z_report);
    }

    public function test_a_difference_within_tolerance_needs_no_approval(): void
    {
        $this->sell();

        $this->close(['counted_total' => 245000])->assertOk()->assertJsonPath('cash_difference', '-5000.00');
    }

    public function test_a_difference_above_tolerance_is_refused_without_approval_and_the_shift_stays_open(): void
    {
        $this->sell();

        $this->close(['counted_total' => 200000])->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertSame('open', $this->shift->fresh()->status);
    }

    public function test_an_approval_is_bound_to_the_exact_typed_total(): void
    {
        $this->sell();
        $outlet = (int) $this->shift->outlet_id;
        $forOther = $this->overrideToken('variance', ['session_id' => $this->shift->id, 'counted_total' => 190000], $outlet);

        $this->close(['counted_total' => 200000, 'variance_override_token' => $forOther])->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertSame('open', $this->shift->fresh()->status);

        $token = $this->overrideToken('variance', ['session_id' => $this->shift->id, 'counted_total' => 200000], $outlet);
        $res = $this->close(['counted_total' => 200000, 'variance_override_token' => $token])->assertOk();
        $res->assertJsonPath('cash_difference', '-50000.00');
        $this->assertNotNull($res->json('variance_override_id'));
    }

    public function test_a_pending_qris_payment_blocks_a_typed_total_close(): void
    {
        $channel = PaymentChannel::factory()->create();
        $this->sell('qr_ewallet', ['channel_id' => $channel->id]);

        $this->close(['counted_total' => 150000])->assertStatus(409)->assertJsonPath('code', 'pending_payments');
        $this->assertSame('open', $this->shift->fresh()->status);
    }

    public function test_an_open_tab_blocks_a_typed_total_close(): void
    {
        $table = \App\Models\DiningTable::factory()->create();
        $this->postJson('/api/v1/tabs', ['session_id' => $this->shift->id, 'table_id' => $table->id, 'guest_count' => 2])->assertCreated();

        $this->close(['counted_total' => 150000])->assertStatus(409)->assertJsonPath('code', 'open_tabs');
    }

    public function test_both_counting_methods_together_are_refused(): void
    {
        $this->close(['counted_total' => 150000, 'counted_by_denomination' => [['denomination' => 100000, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('counted_total');
        $this->assertSame('open', $this->shift->fresh()->status);
    }

    public function test_the_typed_total_must_be_a_non_negative_whole_rupiah_number(): void
    {
        foreach ([-1, 'abc', 1500.5] as $bad) {
            $this->close(['counted_total' => $bad])->assertStatus(422)->assertJsonValidationErrors('counted_total');
        }
        $this->assertSame('open', $this->shift->fresh()->status);

        $this->close(['counted_total' => 0])->assertStatus(409); // 0 vs 150.000 → selisih di atas toleransi (bukan galat bentuk)
    }

    public function test_a_retry_with_the_same_idempotency_key_closes_once(): void
    {
        $key = (string) Str::uuid();
        $first = $this->close(['counted_total' => 150000], $key)->assertOk();
        $second = $this->close(['counted_total' => 150000], $key)->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'session_closed')->count());
    }

    public function test_the_legacy_closing_cash_path_is_unchanged(): void
    {
        $this->close(['closing_cash' => 150000])->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('cash_difference', '0.00');
        $this->assertNull($this->shift->fresh()->counted_total, 'jalur lama tidak mengisi counted_total');
    }
}
