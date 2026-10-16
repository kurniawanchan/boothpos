<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\TestCase;

/** 048 / S3 — kas keluar di atas ambang tidak pernah tercatat tanpa persetujuan `cash_out` yang sah (SC-006). */
class OverrideCashOutTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;

    private CashierSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $this->shift = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open', 'opening_cash' => 500000]);
    }

    private function cashOut(array $body)
    {
        return $this->postJson("/api/v1/sessions/{$this->shift->id}/cash-movements", $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_cash_out_above_the_threshold_is_refused_without_approval_and_records_nothing(): void
    {
        $this->cashOut(['direction' => 'out', 'amount' => 200001, 'reason_code' => 'supplier'])
            ->assertStatus(409)->assertJsonPath('code', 'override_required');

        $this->assertSame(0, CashMovement::count());
    }

    public function test_cash_out_exactly_at_the_threshold_needs_no_approval(): void
    {
        $this->cashOut(['direction' => 'out', 'amount' => 200000, 'reason_code' => 'supplier'])->assertCreated();
        $this->assertSame(1, CashMovement::count());
    }

    public function test_an_approved_cash_out_is_recorded_once_with_the_approved_amount(): void
    {
        $body = ['direction' => 'out', 'amount' => 250000, 'reason_code' => 'supplier'];
        $token = $this->overrideToken('cash_out', ['session_id' => $this->shift->id] + $body, (int) $this->shift->outlet_id);

        $this->cashOut($body + ['override_token' => $token])->assertCreated()->assertJsonPath('amount', '250000.00');
        $this->assertSame(1, CashMovement::count());
    }

    public function test_an_approval_for_a_different_amount_cannot_release_this_cash_out(): void
    {
        $token = $this->overrideToken('cash_out', ['session_id' => $this->shift->id, 'direction' => 'out', 'amount' => 250000, 'reason_code' => 'supplier'], (int) $this->shift->outlet_id);

        $this->cashOut(['direction' => 'out', 'amount' => 300000, 'reason_code' => 'supplier', 'override_token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_an_approval_for_one_session_cannot_be_used_on_another(): void
    {
        $token = $this->overrideToken('cash_out', ['session_id' => $this->shift->id, 'direction' => 'out', 'amount' => 250000, 'reason_code' => 'supplier'], (int) $this->shift->outlet_id);

        // Shift lain milik pengguna yang sama, aksi dan jumlah sama persis: token tidak boleh berlaku.
        $other = CashierSession::factory()->create(['user_id' => $this->shift->user_id, 'status' => 'open', 'opening_cash' => 500000, 'outlet_id' => $this->shift->outlet_id]);
        $this->postJson("/api/v1/sessions/{$other->id}/cash-movements", ['direction' => 'out', 'reason_code' => 'supplier', 'amount' => 250000, 'override_token' => $token], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');

        $this->assertDatabaseCount('cash_movements', 0);
    }
}
