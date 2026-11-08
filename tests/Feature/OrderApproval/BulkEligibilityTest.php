<?php

namespace Tests\Feature\OrderApproval;

use App\Models\Order;
use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 064 (US3) — selektif: hanya yang layak dicatat, sisanya dilewati dengan alasan, hitungan selalu menjumlah. */
class BulkEligibilityTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_mixed_batch_records_only_the_eligible_and_explains_every_skip(): void
    {
        $w = $this->buildApprovalWorld();
        [$pending, $done, $voided, $otherMode] = $this->makeSalesOfA($w, 4);
        $onlyB = $w['sell']([$w['variants']['kopi']]);          // tak ada item seller A → tak layak untuk userA, dan tak terlihat
        $this->actingAs($w['userA'], 'sanctum');

        $this->post("/api/v1/orders/{$done->id}/approvals", $this->stampPayload($w['a']->id, $this->currentHash($done->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();
        $voided->update(['status' => 'voided']);
        Order::withoutGlobalScopes()->whereKey($otherMode->id)->update(['data_mode' => 'demo']);

        $ids = [$pending->id, $done->id, $voided->id, $otherMode->id, $onlyB->id, 999999];
        $data = $this->bulkApply($ids)->assertOk()->json('data');

        $byId = collect($data['results'])->keyBy('order_id');
        $this->assertSame('approved', $byId[$pending->id]['status']);
        $this->assertSame('already_approved', $byId[$done->id]['reason']);
        $this->assertSame('voided', $byId[$voided->id]['reason']);
        $this->assertSame('not_found', $byId[$otherMode->id]['reason']);
        $this->assertSame('not_found', $byId[$onlyB->id]['reason']);   // seller-scope: sale seller lain = tak terlihat
        $this->assertSame('not_found', $byId[999999]['reason']);
        $this->assertNull($byId[999999]['order_number']);

        $this->assertSame(6, $data['selected']);
        $this->assertSame(6, count($data['results']));
        $this->assertSame($data['selected'], $data['approved_orders'] + $data['skipped_total']);
        $this->assertSame(2, OrderApproval::count()); // yang lama + satu baru
        $this->assertSame(0, OrderApproval::where('order_id', $onlyB->id)->count());
    }

    public function test_a_seller_user_on_a_mixed_sale_approves_only_their_seller(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $data = $this->bulkApply([$w['orderMulti']->id])->assertOk()->json('data');

        $this->assertSame(1, $data['approved_sellers']);
        $this->assertSame([$w['a']->id], OrderApproval::pluck('artist_id')->all());
        $this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals")
            ->assertJsonPath('data.status', 'partial')->assertJsonPath('data.progress', '1/3');
    }

    public function test_counts_add_up_over_random_mixes(): void
    {
        $w = $this->buildApprovalWorld();
        $orders = $this->makeSalesOfA($w, 8);
        $this->actingAs($w['userA'], 'sanctum');
        mt_srand(64);
        foreach ($orders as $i => $o) {
            if ($i % 3 === 1) {
                $o->update(['status' => 'voided']);
            }
        }

        $ids = array_map(fn ($o) => $o->id, $orders);
        shuffle($ids);
        $data = $this->bulkApply($ids)->assertOk()->json('data');

        $this->assertSame(count($ids), $data['approved_orders'] + $data['skipped_total']);
        $this->assertSame(count($ids), count($data['results']));
        $this->assertSame($ids, array_column($data['results'], 'order_id'));
    }
}
