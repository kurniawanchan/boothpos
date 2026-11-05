<?php

namespace Tests\Feature\OrderApproval;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 059 × 058: pengguna ber-seller hanya menjangkau penjualan yang punya barisnya; Owner tak pernah dibatasi. */
class SellerScopeInteractionTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_a_sale_without_any_line_of_the_users_seller_is_a_404_everywhere(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();

        // Persetujuan bergambar milik Seller A di penjualan A+B dibuat oleh owner.
        $this->actingAs($w['owner'], 'sanctum');
        $hash = $this->currentHash($w['orderAB']->id, $w['a']->id);
        $this->post("/api/v1/orders/{$w['orderAB']->id}/approvals", ['method' => 'draw', 'image' => UploadedFile::fake()->image('s.png')] + $this->stampPayload($w['a']->id, $hash), ['Accept' => 'application/json'])->assertCreated();
        $approvalId = \App\Models\OrderApproval::firstOrFail()->id;

        $this->actingAs($w['userC'], 'sanctum'); // Seller C tak punya baris di orderAB
        $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->assertStatus(404);
        $this->post("/api/v1/orders/{$w['orderAB']->id}/approvals", $this->stampPayload($w['c']->id, 'v1:x'), ['Accept' => 'application/json'])->assertStatus(404);
        $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals/{$approvalId}/image")->assertStatus(404);
    }

    public function test_an_approval_of_another_order_is_a_404_through_scoped_binding(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", ['method' => 'draw', 'image' => UploadedFile::fake()->image('s.png')] + $this->stampPayload($w['a']->id, $this->currentHash($w['orderA']->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();
        $approvalId = \App\Models\OrderApproval::firstOrFail()->id;

        $this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals/{$approvalId}/image")->assertStatus(404);
        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals/{$approvalId}/image")->assertOk();
    }

    public function test_an_owner_is_never_restricted_even_with_a_stale_seller_link(): void
    {
        $w = $this->buildApprovalWorld();
        $owner = User::factory()->create(['role' => 'owner', 'artist_id' => $w['a']->id]); // artist_id basi
        $this->actingAs($owner, 'sanctum');

        $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals")->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_the_owner_role_can_approve_on_behalf_for_a_seller_it_is_not_linked_to(): void
    {
        $w = $this->buildApprovalWorld();
        $owner = User::factory()->create(['role' => 'owner', 'artist_id' => $w['a']->id]);
        $this->actingAs($owner, 'sanctum');

        $this->post("/api/v1/orders/{$w['orderAB']->id}/approvals", $this->stampPayload($w['b']->id, $this->currentHash($w['orderAB']->id, $w['b']->id)), ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertTrue((bool) \App\Models\OrderApproval::firstOrFail()->on_behalf);
    }
}
