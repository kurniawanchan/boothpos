<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\MenuChange;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T023) — satu jalur persetujuan: peminta ≠ pemutus, izin, alasan, final.
 */
class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $requester;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $this->requester = User::factory()->create(['role' => 'owner']);
        $role = Role::factory()->create(['name' => 'Restaurant Manager', 'menu_keys' => ['products'], 'permissions' => ['menu.price.approve']]);
        $this->approver = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->requester, 'sanctum');
    }

    private function pendingApproval(): Approval
    {
        $v = $this->makeVariant(stock: 1, price: 10000);
        app(PriceService::class)->setBasePrice($v, 20000, $this->requester);

        return Approval::sole();
    }

    private function assertFailsWith(int $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("harus gagal dengan {$code}");
        } catch (ValidationException $e) {
            $this->assertSame($code, $e->status);
        }
    }

    public function test_threshold_comes_from_the_setting_and_a_zero_price_never_needs_approval(): void
    {
        $svc = app(ApprovalService::class);

        $this->assertFalse($svc->exceedsThreshold(10000, 11500));      // tepat 15% bukan "di atas"
        $this->assertTrue($svc->exceedsThreshold(10000, 11501));
        $this->assertFalse($svc->exceedsThreshold(0, 50000));

        Setting::updateOrCreate(['key' => 'price_approval_threshold_pct'], ['value' => '5', 'type' => 'decimal']);
        $this->assertTrue($svc->exceedsThreshold(10000, 10600));
    }

    public function test_the_requester_can_never_approve_their_own_request(): void
    {
        $a = $this->pendingApproval();

        $this->assertFailsWith(409, fn () => app(ApprovalService::class)->approve($a, $this->requester));
        $this->assertSame(Approval::PENDING, $a->fresh()->decision);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $a = $this->pendingApproval();
        $role = Role::factory()->create(['name' => 'Kasir X', 'menu_keys' => ['products'], 'permissions' => []]);
        $nobody = User::factory()->create(['role_id' => $role->id]);

        $this->assertFailsWith(403, fn () => app(ApprovalService::class)->approve($a, $nobody));
    }

    public function test_another_approver_can_approve_and_the_change_becomes_releasable(): void
    {
        $a = $this->pendingApproval();

        app(ApprovalService::class)->approve($a, $this->approver);

        $fresh = $a->fresh();
        $this->assertSame([Approval::APPROVED, $this->approver->id], [$fresh->decision, $fresh->decided_by]);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame(MenuChange::APPROVED, MenuChange::sole()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'approval_approved', 'entity_id' => $a->id]);
    }

    public function test_rejecting_needs_an_approval_reject_reason_and_blocks_the_change(): void
    {
        $a = $this->pendingApproval();

        $this->assertFailsWith(422, fn () => app(ApprovalService::class)->reject($a, $this->approver, ReasonCode::where('category', 'sold_out')->value('id')));
        app(ApprovalService::class)->reject($a, $this->approver, ReasonCode::where('category', 'approval_reject')->value('id'), 'terlalu mahal');

        $this->assertSame(Approval::REJECTED, $a->fresh()->decision);
        $this->assertSame('terlalu mahal', $a->fresh()->note);
        $this->assertSame(MenuChange::REJECTED, MenuChange::sole()->status);
    }

    public function test_a_decision_is_final(): void
    {
        $a = $this->pendingApproval();
        app(ApprovalService::class)->approve($a, $this->approver);

        $this->assertFailsWith(409, fn () => app(ApprovalService::class)->approve($a, $this->approver));
        $this->assertFailsWith(409, fn () => app(ApprovalService::class)->reject($a, $this->approver, ReasonCode::where('category', 'approval_reject')->value('id')));
    }
}
