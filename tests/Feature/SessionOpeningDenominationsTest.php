<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 048 / T042 — kas awal per pecahan: jumlahnya dihitung SERVER dari pecahan × qty; `opening_cash` lama
 * tetap diterima (klien lama dan SPA web). Pecahan di luar daftar dan pecahan ganda ditolak.
 */
class SessionOpeningDenominationsTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    public function test_the_opening_cash_is_derived_from_the_denominations(): void
    {
        $res = $this->postJson('/api/v1/sessions', [
            'opening_denominations' => [
                ['denomination' => 50000, 'qty' => 2],
                ['denomination' => 10000, 'qty' => 5],
                ['denomination' => 2000, 'qty' => 0],
            ],
        ])->assertCreated()->assertJsonPath('opening_cash', '150000.00');

        $this->assertDatabaseHas('session_opening_denominations', ['session_id' => $res->json('id'), 'denomination' => 50000, 'qty' => 2]);
        // Baris ber-qty nol tidak disimpan: hanya yang benar-benar ada di laci.
        $this->assertDatabaseMissing('session_opening_denominations', ['session_id' => $res->json('id'), 'denomination' => 2000]);
        $this->assertCount(2, $res->json('opening_denominations'));
    }

    public function test_the_legacy_opening_cash_still_works_without_denominations(): void
    {
        $this->postJson('/api/v1/sessions', ['opening_cash' => 100000])
            ->assertCreated()->assertJsonPath('opening_cash', '100000.00')->assertJsonCount(0, 'opening_denominations');
    }

    public function test_an_opening_cash_that_disagrees_with_the_denominations_is_refused(): void
    {
        $this->postJson('/api/v1/sessions', [
            'opening_cash' => 100000,
            'opening_denominations' => [['denomination' => 50000, 'qty' => 3]],
        ])->assertStatus(422)->assertJsonValidationErrors('opening_cash');

        $this->assertDatabaseCount('cashier_sessions', 0);
    }

    public function test_an_opening_cash_that_agrees_with_the_denominations_is_accepted(): void
    {
        $this->postJson('/api/v1/sessions', [
            'opening_cash' => 150000,
            'opening_denominations' => [['denomination' => 50000, 'qty' => 3]],
        ])->assertCreated()->assertJsonPath('opening_cash', '150000.00');
    }

    public function test_a_denomination_outside_the_currency_is_refused(): void
    {
        $this->postJson('/api/v1/sessions', [
            'opening_denominations' => [['denomination' => 3000, 'qty' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('opening_denominations.0.denomination');
    }

    public function test_the_same_denomination_twice_is_refused(): void
    {
        $this->postJson('/api/v1/sessions', [
            'opening_denominations' => [
                ['denomination' => 50000, 'qty' => 1],
                ['denomination' => 50000, 'qty' => 2],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('opening_denominations');
    }

    public function test_a_negative_quantity_is_refused(): void
    {
        $this->postJson('/api/v1/sessions', [
            'opening_denominations' => [['denomination' => 50000, 'qty' => -1]],
        ])->assertStatus(422)->assertJsonValidationErrors('opening_denominations.0.qty');
    }

    public function test_an_unknown_terminal_is_refused_when_opening_the_shift(): void
    {
        $this->withHeaders(['X-Device-Id' => '999999'])
            ->postJson('/api/v1/sessions', ['opening_cash' => 100000])
            ->assertStatus(422)->assertJsonValidationErrors('device_id');

        $this->assertDatabaseCount('cashier_sessions', 0);
    }

    public function test_opening_a_shift_needs_either_an_opening_cash_or_a_denomination_count(): void
    {
        $this->postJson('/api/v1/sessions', [])->assertStatus(422)->assertJsonValidationErrors('opening_cash');
        $this->assertDatabaseCount('cashier_sessions', 0);
    }
}
