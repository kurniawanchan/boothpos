<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OverrideFixtures;
use Tests\TestCase;

/** 078 / US3 — daftar penyetuju membawa `photo_url` supaya pemilih penyetuju menampilkan foto (inisial bila tidak ada). */
class ApproverPhotoTest extends TestCase
{
    use OverrideFixtures;
    use RefreshDatabase;

    private int $outletId;

    protected function setUp(): void
    {
        parent::setUp();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = CashierSession::factory()->create(['user_id' => $cashier->id, 'status' => 'open']);
        $this->outletId = (int) $shift->outlet_id;
        $cashier->outlets()->syncWithoutDetaching([$this->outletId]);
    }

    private function list(): array
    {
        return collect($this->getJson('/api/v1/overrides/approvers?action=cash_out', ['X-Outlet-Id' => $this->outletId])->assertOk()->json())->keyBy('id')->all();
    }

    public function test_an_approver_with_a_photo_gets_its_url_and_one_without_gets_null(): void
    {
        $with = $this->approver($this->outletId);
        $with->forceFill(['photo_path' => 'users/approver.jpg'])->save();
        $without = $this->approver($this->outletId, 'admin');

        $rows = $this->list();

        $this->assertStringEndsWith('/storage/users/approver.jpg', $rows[$with->id]['photo_url']);
        $this->assertNull($rows[$without->id]['photo_url']);
    }

    public function test_the_keys_are_exactly_id_name_role_and_photo_url(): void
    {
        $approver = $this->approver($this->outletId);

        $this->assertSame(['id', 'name', 'role', 'photo_url'], array_keys($this->list()[$approver->id]));
    }

    public function test_only_eligible_approvers_appear_as_before(): void
    {
        $approver = $this->approver($this->outletId);
        $plainCashier = User::factory()->create(['role' => 'cashier', 'photo_path' => 'users/x.jpg']);
        $plainCashier->outlets()->syncWithoutDetaching([$this->outletId]);

        $ids = array_keys($this->list());

        $this->assertContains($approver->id, $ids);
        $this->assertNotContains($plainCashier->id, $ids);
    }
}
