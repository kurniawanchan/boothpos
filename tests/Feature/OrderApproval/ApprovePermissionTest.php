<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovePermissionTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function stamp(int $orderId, int $artistId, ?string $hash = null)
    {
        $hash ??= $this->currentHash($orderId, $artistId);

        return $this->post("/api/v1/orders/{$orderId}/approvals", $this->stampPayload($artistId, $hash), ['Accept' => 'application/json']);
    }

    public function test_a_seller_user_approves_their_own_seller_only(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $hashB = $this->currentHash($w['orderAB']->id, $w['b']->id);
        $this->stamp($w['orderAB']->id, $w['b']->id, $hashB)->assertStatus(403)->assertJsonPath('code', 'approval_not_allowed');
        $this->assertSame(0, OrderApproval::count());

        $this->stamp($w['orderAB']->id, $w['a']->id)->assertCreated();
        $this->assertSame([$w['a']->id], OrderApproval::pluck('artist_id')->all());
    }

    public function test_owner_and_admin_approve_on_behalf_of_any_seller(): void
    {
        $w = $this->buildApprovalWorld();

        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($w['orderAB']->id, $w['a']->id)->assertCreated();
        $this->actingAs($w['admin'], 'sanctum');
        $this->stamp($w['orderAB']->id, $w['b']->id)->assertCreated()->assertJsonPath('data.status', 'full');

        $this->assertSame([true, true], OrderApproval::orderBy('id')->pluck('on_behalf')->all());
        $this->assertSame([$w['owner']->id, $w['admin']->id], OrderApproval::orderBy('id')->pluck('approved_by')->all());
    }

    public function test_an_unlinked_cashier_cannot_approve(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['cashier'], 'sanctum');

        $this->stamp($w['orderA']->id, $w['a']->id)->assertStatus(403)->assertJsonPath('code', 'approval_not_allowed');
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_a_role_without_the_approve_action_is_refused_even_when_linked_to_the_seller(): void
    {
        $w = $this->buildApprovalWorld();
        $role = Role::create([
            'name' => 'Seller Lihat Saja', 'menu_keys' => ['sales'], 'menu_actions' => ['sales' => ['void']], 'is_system' => false,
        ]);
        $limited = User::factory()->create(['role_id' => $role->id, 'artist_id' => $w['a']->id]);
        $this->actingAs($limited, 'sanctum');

        $hash = $this->currentHash($w['orderA']->id, $w['a']->id);
        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", $this->stampPayload($w['a']->id, $hash), ['Accept' => 'application/json'])
            ->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
        $this->assertSame(0, OrderApproval::count());

        // …tetapi tetap boleh MELIHAT status, dan tombol tidak ditawarkan.
        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals")->assertOk()->assertJsonPath('data.sellers.0.can_approve', true);
    }

    public function test_a_user_without_the_sales_menu_cannot_read_or_approve(): void
    {
        $w = $this->buildApprovalWorld();
        $role = Role::create(['name' => 'Tanpa Sales', 'menu_keys' => ['products'], 'is_system' => false]);
        $nobody = User::factory()->create(['role_id' => $role->id, 'artist_id' => $w['a']->id]);
        $this->actingAs($nobody, 'sanctum');

        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals")->assertStatus(403);
        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", $this->stampPayload($w['a']->id, 'v1:x'), ['Accept' => 'application/json'])->assertStatus(403);
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $w = $this->buildApprovalWorld();
        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals")->assertStatus(401);
        $this->postJson("/api/v1/orders/{$w['orderA']->id}/approvals", [])->assertStatus(401);
    }
}
