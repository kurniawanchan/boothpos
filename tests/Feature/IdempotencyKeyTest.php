<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 048 / api-gap B15 — `Idempotency-Key` pada tambah/ubah baris dan kirim ke dapur.
 */
class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');
    }

    private function add(int $variantId, string $key, int $qty = 1)
    {
        return $this->postJson("/api/v1/tabs/{$this->tab}/items", ['variant_id' => $variantId, 'qty' => $qty], ['Idempotency-Key' => $key]);
    }

    public function test_a_retried_add_line_does_not_create_a_second_line(): void
    {
        $v = $this->makeVariant(stock: 10, price: 20000);

        $first = $this->add($v->id, 'key-1', 2)->assertCreated();
        $retry = $this->add($v->id, 'key-1', 2)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json(), $retry->json());
        $this->getJson("/api/v1/tabs/{$this->tab}")->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.qty', 2);
    }

    public function test_without_the_header_behaviour_is_unchanged(): void
    {
        $v = $this->makeVariant(stock: 10);

        $this->apiAddLine($this->tab, $v->id)->assertCreated();
        $this->apiAddLine($this->tab, $v->id)->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
    }

    public function test_same_key_for_a_different_request_is_rejected(): void
    {
        $v = $this->makeVariant(stock: 10);

        $this->add($v->id, 'key-2', 1)->assertCreated();
        $this->add($v->id, 'key-2', 3)->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_a_rejected_request_is_replayed_with_the_same_rejection(): void
    {
        $v = $this->makeVariant(stock: 1);

        // Ditolak aturan bisnis (stok kurang) → jawaban 4xx disimpan dan diputar ulang apa adanya.
        $first = $this->add($v->id, 'key-3', 5);
        $first->assertStatus(409);
        $this->add($v->id, 'key-3', 5)->assertStatus(409)->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $v = $this->makeVariant(stock: 10);
        $this->add($v->id, 'shared', 1)->assertCreated();

        $other = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($other, 'sanctum');

        // Pengguna lain dengan kunci sama bukan replay: ia diproses sendiri (di sini ditolak karena bukan tab-nya,
        // yang penting BUKAN jawaban tersimpan milik kasir pertama).
        $this->add($v->id, 'shared', 1)->assertHeaderMissing('Idempotency-Replayed');
    }

    public function test_an_overlong_key_is_rejected(): void
    {
        $v = $this->makeVariant(stock: 10);

        $this->add($v->id, str_repeat('k', 101))->assertStatus(422)->assertJsonPath('code', 'idempotency_key_invalid');
    }
}
