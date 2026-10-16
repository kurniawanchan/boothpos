<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 048 / T044 — kas masuk/keluar shift (api-gap B3). Aturan: alasan wajib dan sah untuk arahnya, jumlah
 * positif, hanya di shift terbuka milik pengguna, dan kas keluar di atas ambang ditolak 409 sampai
 * persetujuan (S3/US4) ada. Dicatat sekali walau permintaan diulang (Idempotency-Key).
 */
class CashMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private CashierSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = CashierSession::factory()->create([
            'user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 150000,
        ]);
    }

    private function movement(array $body, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/sessions/{$this->shift->id}/cash-movements", $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_cash_in_and_out_are_recorded_listed_and_move_the_expected_cash(): void
    {
        $this->movement(['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => 100000])
            ->assertCreated()->assertJsonPath('direction', 'in')->assertJsonPath('amount', '100000.00');
        $this->movement(['direction' => 'out', 'reason_code' => 'petty_cash', 'amount' => 30000, 'reason_note' => 'es batu'])
            ->assertCreated()->assertJsonPath('reason_note', 'es batu');

        $this->getJson("/api/v1/sessions/{$this->shift->id}/cash-movements")
            ->assertOk()->assertJsonCount(2, 'data');

        // Ringkasan kasir memperlihatkan arus kas, tetapi TIDAK kas seharusnya (blind count).
        $this->getJson("/api/v1/sessions/{$this->shift->id}/summary")
            ->assertOk()->assertJsonPath('cash_in', '100000.00')->assertJsonPath('cash_out', '30000.00')->assertJsonMissingPath('expected_cash');

        // Kas seharusnya = modal 150.000 + masuk 100.000 − keluar 30.000 = 220.000; baru tampil di respons tutup.
        $this->postJson("/api/v1/sessions/{$this->shift->id}/close", ['counted_by_denomination' => [['denomination' => 100000, 'qty' => 2], ['denomination' => 10000, 'qty' => 2]]])
            ->assertOk()->assertJsonPath('expected_cash', '220000.00')->assertJsonPath('cash_difference', '0.00');
    }

    public function test_cash_out_above_the_threshold_needs_approval_and_writes_nothing(): void
    {
        Setting::updateOrCreate(['key' => 'cash_out_approval_threshold'], ['value' => '200000', 'type' => 'string']);

        $this->movement(['direction' => 'out', 'reason_code' => 'supplier', 'amount' => 200001])
            ->assertStatus(409)->assertJsonPath('code', 'override_required');

        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_out_exactly_at_the_threshold_is_allowed(): void
    {
        Setting::updateOrCreate(['key' => 'cash_out_approval_threshold'], ['value' => '200000', 'type' => 'string']);

        $this->movement(['direction' => 'out', 'reason_code' => 'supplier', 'amount' => 200000])->assertCreated();
    }

    public function test_a_reason_must_belong_to_its_direction(): void
    {
        $this->movement(['direction' => 'in', 'reason_code' => 'petty_cash', 'amount' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('reason_code');
        $this->movement(['direction' => 'out', 'reason_code' => 'made_up', 'amount' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('reason_code');
    }

    public function test_the_other_reason_needs_a_written_note(): void
    {
        $this->movement(['direction' => 'out', 'reason_code' => 'other', 'amount' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('reason_note');

        $this->movement(['direction' => 'out', 'reason_code' => 'other', 'amount' => 1000, 'reason_note' => 'ganti lampu'])
            ->assertCreated();
    }

    public function test_the_amount_must_be_positive_with_at_most_two_decimals(): void
    {
        $this->movement(['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->movement(['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => -5])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->movement(['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => 10.005])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_cashier_cannot_record_money_on_someone_elses_shift(): void
    {
        $other = User::factory()->create(['role' => 'cashier']);
        $theirs = CashierSession::factory()->create(['user_id' => $other->id, 'status' => 'open', 'opening_cash' => 0]);

        $this->postJson("/api/v1/sessions/{$theirs->id}/cash-movements", ['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => 1000], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(403)->assertJsonPath('code', 'session_not_owner');

        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_money_cannot_be_recorded_on_a_closed_shift(): void
    {
        $this->shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->movement(['direction' => 'in', 'reason_code' => 'float_topup', 'amount' => 1000])
            ->assertStatus(409)->assertJsonPath('code', 'session_closed');
    }

    public function test_a_retried_request_with_the_same_key_is_recorded_once(): void
    {
        $key = (string) Str::uuid();
        $body = ['direction' => 'out', 'reason_code' => 'petty_cash', 'amount' => 25000];

        $this->movement($body, $key)->assertCreated();
        $this->movement($body, $key);

        $this->assertSame(1, CashMovement::count());
    }
}
