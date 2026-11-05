<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiSellerTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function stamp(int $orderId, int $artistId)
    {
        return $this->post("/api/v1/orders/{$orderId}/approvals", $this->stampPayload($artistId, $this->currentHash($orderId, $artistId)), ['Accept' => 'application/json']);
    }

    public function test_the_specs_example_has_three_requirements_not_four(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');

        $r = $this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals")->assertOk();
        $r->assertJsonPath('data.total', 3)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.progress', '0/3');

        $bySeller = collect($r->json('data.sellers'))->keyBy('artist_id');
        $this->assertCount(2, $bySeller[$w['a']->id]['items']);
        $this->assertCount(1, $bySeller[$w['b']->id]['items']);
        $this->assertCount(1, $bySeller[$w['c']->id]['items']);
    }

    public function test_status_moves_pending_partial_full_as_each_seller_approves(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderMulti']->id;

        $this->actingAs($w['userA'], 'sanctum');
        $this->stamp($id, $w['a']->id)->assertCreated()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.progress', '1/3');

        $this->actingAs($w['userB'], 'sanctum');
        $this->stamp($id, $w['b']->id)->assertCreated()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.progress', '2/3')
            ->assertJsonPath('data.pending', 1);

        // Seller C belum: status keseluruhan tetap "partial", dan C tetap menunggu.
        $this->getJson("/api/v1/orders/{$id}/approvals")->assertJsonPath('data.status', 'partial');

        $this->actingAs($w['userC'], 'sanctum');
        $this->stamp($id, $w['c']->id)->assertCreated()->assertJsonPath('data.status', 'full')->assertJsonPath('data.progress', '3/3');
    }

    public function test_one_sellers_approval_creates_and_changes_no_other_row(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $this->stamp($w['orderMulti']->id, $w['a']->id)->assertCreated();

        $this->assertSame([$w['a']->id], OrderApproval::pluck('artist_id')->all());
        $status = collect($this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals")->json('data.sellers'))->pluck('status', 'artist_id');
        $this->assertSame(['approved', 'pending', 'pending'], [$status[$w['a']->id], $status[$w['b']->id], $status[$w['c']->id]]);
    }

    public function test_a_seller_user_sees_the_whole_sale_but_can_act_only_on_their_own_seller(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userB'], 'sanctum');

        $sellers = collect($this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals")->assertOk()->json('data.sellers'))->keyBy('artist_id');
        $this->assertCount(3, $sellers); // status keseluruhan tetap seluruh penjualan (058 Q1:B)
        $this->assertSame(
            [false, true, false],
            [$sellers[$w['a']->id]['can_approve'], $sellers[$w['b']->id]['can_approve'], $sellers[$w['c']->id]['can_approve']],
        );
        $this->assertSame('Seller Approval Alfa', $sellers[$w['a']->id]['artist_name'], 'nama seller lain tetap terlihat walau scope seller aktif');
    }

    public function test_can_approve_is_false_once_approved_and_for_a_voided_sale(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($w['orderAB']->id, $w['a']->id)->assertCreated();

        $sellers = collect($this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->json('data.sellers'))->keyBy('artist_id');
        $this->assertFalse($sellers[$w['a']->id]['can_approve']);
        $this->assertTrue($sellers[$w['b']->id]['can_approve']);

        $w['orderAB']->update(['status' => 'voided']);
        $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")
            ->assertJsonPath('data.sellers.1.can_approve', false)->assertJsonPath('data.sellers.0.can_approve', false);
    }

    public function test_progress_numbers_are_unique_sellers_not_items(): void
    {
        $w = $this->buildApprovalWorld();
        // 5 baris: dua penjualan item A pada satu keranjang tetap SATU syarat.
        $order = $w['sell']([$w['variants']['indomie'], $w['variants']['esTeh'], $w['variants']['kopi']]);
        $this->actingAs($w['owner'], 'sanctum');

        $this->getJson("/api/v1/orders/{$order->id}/approvals")->assertJsonPath('data.total', 2)->assertJsonPath('data.progress', '0/2');
    }
}
