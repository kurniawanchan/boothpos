<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OverrideFixtures;
use Tests\TestCase;

/** 048 / S3 — tutup shift dengan selisih di atas toleransi hanya lewat persetujuan `variance` yang terikat ke hitungan. */
class OverrideCloseVarianceTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;

    private User $cashier;
    private CashierSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 150000]);
    }

    /** @return list<array{denomination: int, qty: int}> */
    private function count100k(): array
    {
        return [['denomination' => 100000, 'qty' => 1]]; // selisih −50.000 (di atas toleransi 5.000)
    }

    private function close(array $body)
    {
        return $this->postJson("/api/v1/sessions/{$this->shift->id}/close", $body);
    }

    public function test_a_variance_above_tolerance_is_refused_without_approval_and_the_shift_stays_open(): void
    {
        $this->close(['counted_by_denomination' => $this->count100k()])
            ->assertStatus(409)->assertJsonPath('code', 'override_required');

        $this->assertSame('open', $this->shift->fresh()->status);
    }

    public function test_an_approved_variance_closes_the_shift_and_records_the_approval(): void
    {
        $token = $this->overrideToken('variance', ['session_id' => $this->shift->id, 'counted_by_denomination' => $this->count100k()], (int) $this->shift->outlet_id);

        $res = $this->close(['counted_by_denomination' => $this->count100k(), 'variance_override_token' => $token])->assertOk();

        $res->assertJsonPath('status', 'closed')->assertJsonPath('cash_difference', '-50000.00');
        $this->assertNotNull($res->json('variance_override_id'));
    }

    public function test_an_approval_for_a_different_count_cannot_close_the_shift(): void
    {
        $token = $this->overrideToken('variance', ['session_id' => $this->shift->id, 'counted_by_denomination' => [['denomination' => 50000, 'qty' => 1]]], (int) $this->shift->outlet_id);

        $this->close(['counted_by_denomination' => $this->count100k(), 'variance_override_token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertSame('open', $this->shift->fresh()->status);
    }

    public function test_a_variance_within_tolerance_needs_no_approval(): void
    {
        $this->close(['counted_by_denomination' => [['denomination' => 100000, 'qty' => 1], ['denomination' => 50000, 'qty' => 1]]])
            ->assertOk()->assertJsonPath('cash_difference', '0.00');
    }
}
