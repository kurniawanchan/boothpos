<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\MenuChange;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T065) — /approvals.
 */
class ApprovalApiTest extends TestCase
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
        $v = $this->makeVariant(stock: 1, price: 10000);
        $v->product->update(['name' => 'Kopi Susu Gula Aren']);
        app(PriceService::class)->setBasePrice($v, 15000, $this->requester);
    }

    public function test_the_queue_lists_pending_approvals_with_the_old_and_new_price(): void
    {
        $this->actingAs($this->approver, 'sanctum');

        $res = $this->getJson('/api/v1/approvals?kind=menu_price&status=pending')->assertOk();

        $row = $res->json('data.0');
        $this->assertEquals(['Kopi Susu Gula Aren', '10000.00', '15000.00', 50.0, $this->requester->name], [$row['product_name'], $row['old_price'], $row['new_price'], $row['difference_pct'], $row['requested_by']]);
        $this->assertEquals(15.0, $res->json('threshold_pct'));
    }

    public function test_another_approver_approves_and_the_requester_cannot(): void
    {
        $id = Approval::sole()->id;

        $this->actingAs($this->requester, 'sanctum');
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertStatus(409);

        $this->actingAs($this->approver, 'sanctum');
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertOk()->assertJsonPath('decision', 'approved');
        $this->assertSame(MenuChange::APPROVED, MenuChange::sole()->status);
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertStatus(409);
    }

    public function test_reject_needs_a_valid_reason_and_blocks_the_change(): void
    {
        $id = Approval::sole()->id;
        $this->actingAs($this->approver, 'sanctum');

        $this->postJson("/api/v1/approvals/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/approvals/{$id}/reject", ['reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id')])->assertStatus(422);
        $this->postJson("/api/v1/approvals/{$id}/reject", ['reason_code_id' => ReasonCode::where('category', 'approval_reject')->value('id'), 'note' => 'mahal'])
            ->assertOk()->assertJsonPath('decision', 'rejected');
        $this->assertSame(MenuChange::REJECTED, MenuChange::sole()->status);
    }

    public function test_a_user_without_the_permission_gets_403_everywhere(): void
    {
        $id = Approval::sole()->id;
        $role = Role::factory()->create(['menu_keys' => ['products'], 'permissions' => []]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->getJson('/api/v1/approvals')->assertForbidden();
        $this->postJson("/api/v1/approvals/{$id}/approve")->assertStatus(403);
        $this->assertSame(Approval::PENDING, Approval::sole()->decision);
    }

    public function test_decisions_are_refused_while_releases_are_disabled(): void
    {
        $id = Approval::sole()->id;
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'false', 'type' => 'boolean']);
        $this->actingAs($this->approver, 'sanctum');

        $this->postJson("/api/v1/approvals/{$id}/approve")->assertStatus(409);
    }
}
