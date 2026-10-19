<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 057 / T018 — nomor salinan cetak ulang berasal dari server: payload order membawa `reprint_count`, dan permintaan
 * tanpa `copy_no` ditolak (422). Nomor berikutnya = reprint_count + 2.
 */
class ReprintCopyFromOrderTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private CashierSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = $this->makeShift($this->cashier, ['opening_cash' => 0]);
    }

    private function completedSale(): int
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->makeVariant(stock: 10, price: 8000)->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 8000]],
        ])->assertCreated()->json('id');
    }

    public function test_the_order_payload_carries_the_reprint_count_starting_at_zero(): void
    {
        $id = $this->completedSale();

        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('reprint_count', 0);
    }

    public function test_a_reprint_without_copy_no_is_refused(): void
    {
        $id = $this->completedSale();

        $this->postJson("/api/v1/orders/{$id}/reprints", [])->assertUnprocessable()->assertJsonValidationErrors(['copy_no']);
    }

    public function test_the_next_copy_number_follows_the_reprint_count(): void
    {
        $id = $this->completedSale();

        $this->postJson("/api/v1/orders/{$id}/reprints", ['copy_no' => 2])->assertOk();
        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('reprint_count', 1);

        // Salinan 2 sudah dicetak: nomor 2 sekarang basi (409). Salinan 3 melewati cetak ulang gratis, jadi butuh persetujuan.
        $this->postJson("/api/v1/orders/{$id}/reprints", ['copy_no' => 2])->assertStatus(409);
        $this->postJson("/api/v1/orders/{$id}/reprints", ['copy_no' => 3])->assertStatus(409);
    }
}
