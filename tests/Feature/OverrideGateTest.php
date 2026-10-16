<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashMovement;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\OverrideFixtures;
use Tests\TestCase;

/**
 * 048 / S3 — gerbang persetujuan (research D11, SC-006). Setiap kode galat dibuktikan lewat aksi nyata
 * (kas keluar di atas ambang, yang dijaga di titik aksi): tanpa token, token salah, token terpakai atau
 * kedaluwarsa, penyetuju tidak sah, PIN salah, dan kunci 5-salah.
 */
class OverrideGateTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;

    private User $cashier;
    private CashierSession $shift;
    private int $outletId;

    /** Body kas keluar di atas ambang (Rp 250.000 > Rp 200.000). */
    private const BIG_OUT = ['direction' => 'out', 'amount' => 250000, 'reason_code' => 'supplier'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->shift = CashierSession::factory()->create(['user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 500000]);
        $this->outletId = (int) $this->shift->outlet_id;
        $this->cashier->outlets()->syncWithoutDetaching([$this->outletId]);
    }

    /** Subjek kas keluar besar untuk sesi uji ini (subjek memuat id sesi sejak S3 tinjauan). */
    private function big(): array
    {
        return ['session_id' => $this->shift->id] + self::BIG_OUT;
    }

    private function cashOut(array $body, ?string $token = null)
    {
        return $this->postJson("/api/v1/sessions/{$this->shift->id}/cash-movements", $body + ($token ? ['override_token' => $token] : []), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_valid_token_is_single_use_and_the_body_alone_defines_the_subject(): void
    {
        $token = $this->overrideToken('cash_out', $this->big(), $this->outletId);

        // Klien tidak bisa menyuntikkan hash: bidang asing diabaikan, subjek tetap dari body aksi.
        $this->cashOut(self::BIG_OUT, $token)->assertCreated();
        $this->cashOut(self::BIG_OUT, $token)->assertStatus(409)->assertJsonPath('code', 'override_expired');

        $this->assertSame(1, CashMovement::count());
    }

    public function test_a_missing_token_is_override_required(): void
    {
        $this->cashOut(self::BIG_OUT)->assertStatus(409)->assertJsonPath('code', 'override_required');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_a_token_for_another_action_or_body_is_override_mismatch(): void
    {
        $tokenForOtherAction = $this->overrideToken('availability', ['reason_code_id' => 1, 'note' => null], $this->outletId);
        $this->cashOut(self::BIG_OUT, $tokenForOtherAction)->assertStatus(422)->assertJsonPath('code', 'override_mismatch');

        $tokenForOtherAmount = $this->overrideToken('cash_out', $this->big(), $this->outletId);
        $this->cashOut(['direction' => 'out', 'amount' => 260000, 'reason_code' => 'supplier'], $tokenForOtherAmount)
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');

        $this->cashOut(self::BIG_OUT, 'tidak-ada-token-seperti-ini')->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_a_token_is_bound_to_the_requester_who_asked_for_it(): void
    {
        $token = $this->overrideToken('cash_out', $this->big(), $this->outletId);

        $other = User::factory()->create(['role' => 'cashier']);
        $otherShift = CashierSession::factory()->create(['user_id' => $other->id, 'status' => 'open', 'opening_cash' => 500000, 'outlet_id' => $this->outletId]);
        $this->actingAs($other, 'sanctum');
        $this->postJson("/api/v1/sessions/{$otherShift->id}/cash-movements", self::BIG_OUT + ['override_token' => $token], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('code', 'override_mismatch');
    }

    public function test_an_expired_token_is_override_expired(): void
    {
        $token = $this->overrideToken('cash_out', $this->big(), $this->outletId);

        $this->travel(121)->seconds();

        $this->cashOut(self::BIG_OUT, $token)->assertStatus(409)->assertJsonPath('code', 'override_expired');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_an_approver_without_the_action_permission_is_refused(): void
    {
        $cashierApprover = User::factory()->create(['role' => 'cashier']);
        $cashierApprover->forceFill(['pin_hash' => \Illuminate\Support\Facades\Hash::make(self::APPROVER_PIN), 'pin_failed_attempts' => 0])->save();
        $cashierApprover->outlets()->syncWithoutDetaching([$this->outletId]);

        $this->issueOverride('cash_out', $this->big(), $this->outletId, $cashierApprover)
            ->assertStatus(403)->assertJsonPath('code', 'approver_not_permitted');
    }

    public function test_an_approver_outside_the_outlet_is_refused(): void
    {
        $role = Role::factory()->create(['menu_keys' => ['pos'], 'permissions' => [PermissionCatalog::CASH_OUT_APPROVE], 'all_outlets' => false]);
        $stranger = User::factory()->create(['role_id' => $role->id]);
        $stranger->forceFill(['pin_hash' => \Illuminate\Support\Facades\Hash::make(self::APPROVER_PIN), 'pin_failed_attempts' => 0])->save();
        // UserFactory menautkan pengguna baru ke SEMUA outlet aktif; lepaskan dulu, lalu tautkan hanya ke outlet lain.
        $stranger->outlets()->detach();
        $elsewhere = Outlet::factory()->create();
        $stranger->outlets()->syncWithoutDetaching([$elsewhere->id]);
        $this->assertFalse($stranger->outlets()->whereKey($this->outletId)->exists(), 'penyetuju uji harus benar-benar di luar outlet');

        $this->issueOverride('cash_out', $this->big(), $this->outletId, $stranger)
            ->assertStatus(403)->assertJsonPath('code', 'approver_not_permitted');
    }

    public function test_the_requester_cannot_approve_their_own_request(): void
    {
        $this->issueOverride('cash_out', $this->big(), $this->outletId, $this->cashier)
            ->assertStatus(409)->assertJsonPath('code', 'same_person');
    }

    public function test_a_wrong_pin_is_invalid_pin_with_the_attempts_left(): void
    {
        $approver = $this->approver($this->outletId);

        $this->issueOverride('cash_out', $this->big(), $this->outletId, $approver, '000000')
            ->assertStatus(401)->assertJsonPath('code', 'invalid_pin')->assertJsonPath('attempts_remaining', 4);
    }

    public function test_five_wrong_pins_lock_the_approver_on_the_same_counter_as_login(): void
    {
        $approver = $this->approver($this->outletId);

        for ($i = 0; $i < 4; $i++) {
            $this->issueOverride('cash_out', $this->big(), $this->outletId, $approver, '000000')->assertStatus(401);
        }
        $this->issueOverride('cash_out', $this->big(), $this->outletId, $approver, '000000')
            ->assertStatus(429)->assertJsonPath('code', 'pin_locked');

        // Terkunci: PIN yang benar pun ditolak sampai masa kunci habis.
        $this->issueOverride('cash_out', $this->big(), $this->outletId, $approver)
            ->assertStatus(429)->assertJsonPath('code', 'pin_locked');
        $this->assertSame(0, \App\Models\Override::count());
    }

    public function test_the_approvers_list_is_a_bare_array_of_id_name_and_role_without_the_requester(): void
    {
        $approver = $this->approver($this->outletId);
        $cashierInOutlet = User::factory()->create(['role' => 'cashier']);
        $cashierInOutlet->outlets()->syncWithoutDetaching([$this->outletId]);

        $res = $this->getJson('/api/v1/overrides/approvers?action=cash_out', ['X-Outlet-Id' => $this->outletId])->assertOk();

        $this->assertIsArray($res->json());
        $this->assertArrayNotHasKey('data', $res->json());
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($approver->id, $ids);
        $this->assertNotContains($this->cashier->id, $ids);
        $this->assertNotContains($cashierInOutlet->id, $ids);
        $this->assertSame(['id', 'name', 'role'], array_keys(collect($res->json())->firstWhere('id', $approver->id)));
    }
}
