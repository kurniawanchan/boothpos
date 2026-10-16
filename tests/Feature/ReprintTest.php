<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / T060 — cetak ulang struk: salinan asli = 1, cetak ulang pertama gratis (reprint_free_copies), berikutnya
 * butuh persetujuan yang terikat ke order dan nomor salinan itu.
 */
class ReprintTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use OverrideFixtures;

    private User $cashier;

    private CashierSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
    }

    /** Penjualan POS tunai lunas (uang pas) — order selesai. */
    private function completedSale(): int
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->makeVariant(stock: 10, price: 8000)->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 8000]],
        ])->assertCreated()->json('id');
    }

    private function reprint(int $orderId, int $copyNo, ?string $token = null)
    {
        $body = ['copy_no' => $copyNo] + ($token !== null ? ['override_token' => $token] : []);

        return $this->postJson("/api/v1/orders/{$orderId}/reprints", $body);
    }

    public function test_the_first_reprint_is_free_and_counts_the_copy(): void
    {
        $order = $this->completedSale();

        $this->reprint($order, 2)->assertOk()->assertJsonPath('copy_no', 2)->assertJsonPath('free', true);
        $this->assertSame(1, Order::find($order)->receipt_copies);
    }

    public function test_a_second_reprint_without_a_token_needs_approval(): void
    {
        $order = $this->completedSale();
        $this->reprint($order, 2)->assertOk();

        $this->reprint($order, 3)->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertSame(1, Order::find($order)->receipt_copies, 'salinan tanpa persetujuan tidak boleh tercatat');
    }

    public function test_a_second_reprint_with_an_approval_for_that_copy_counts_the_copy(): void
    {
        $order = $this->completedSale();
        $this->reprint($order, 2)->assertOk();
        $token = $this->overrideToken('reprint', ['order_id' => $order, 'copy_no' => 3], (int) $this->shift->outlet_id);

        $this->reprint($order, 3, $token)->assertOk()->assertJsonPath('copy_no', 3)->assertJsonPath('free', false);
        $this->assertSame(2, Order::find($order)->receipt_copies);
    }

    public function test_a_stale_copy_number_is_refused_with_the_current_expectation(): void
    {
        $order = $this->completedSale();
        $this->reprint($order, 2)->assertOk();

        // Salinan 3 sudah berubah: salinan berikutnya adalah 3 (receipt_copies 1 + 2).
        $this->reprint($order, 5)->assertStatus(409)->assertJsonPath('code', 'reprint_copy_changed')->assertJsonPath('copy_no', 3);
    }

    public function test_an_approval_for_one_order_cannot_reprint_another(): void
    {
        $a = $this->completedSale();
        $b = $this->completedSale();
        $this->reprint($a, 2)->assertOk();
        $this->reprint($b, 2)->assertOk();

        $tokenForA = $this->overrideToken('reprint', ['order_id' => $a, 'copy_no' => 3], (int) $this->shift->outlet_id);

        $this->reprint($b, 3, $tokenForA)->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertSame(1, Order::find($b)->receipt_copies);
    }

    public function test_an_open_tab_has_no_receipt_to_reprint(): void
    {
        $tab = $this->apiOpenTab($this->shift->id)->json('id');
        $this->apiAddLine($tab, $this->makeVariant(stock: 10, price: 8000)->id, 1)->assertCreated();

        $this->reprint($tab, 2)->assertStatus(409)->assertJsonPath('code', 'order_not_completed');
    }
}
