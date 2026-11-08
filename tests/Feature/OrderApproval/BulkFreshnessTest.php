<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 064 (US3) — yang disetujui = yang ditinjau: isi berubah sejak pratinjau → penjualan itu dilewati. */
class BulkFreshnessTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_a_sale_changed_after_the_preview_is_skipped_and_the_rest_is_approved(): void
    {
        $w = $this->buildApprovalWorld();
        [$stable, $changes] = $this->makeSalesOfA($w, 2);
        $this->actingAs($w['userA'], 'sanctum');
        $ids = [$stable->id, $changes->id];

        $plan = $this->bulkPreview($ids)->json('data.plan');
        OrderItem::where('order_id', $changes->id)->firstOrFail()->update(['qty' => 9]);

        $data = $this->bulkApply($ids, 'stamp', $plan)->assertOk()->json('data');

        $byId = collect($data['results'])->keyBy('order_id');
        $this->assertSame('approved', $byId[$stable->id]['status']);
        $this->assertSame('changed', $byId[$changes->id]['reason']);
        $this->assertSame(0, OrderApproval::where('order_id', $changes->id)->count());
    }

    public function test_an_empty_or_forged_plan_can_only_cause_changed_never_an_approval(): void
    {
        $w = $this->buildApprovalWorld();
        $o = $this->makeSalesOfA($w, 1)[0];
        $this->actingAs($w['userA'], 'sanctum');

        foreach ([[], [['order_id' => $o->id, 'sellers' => [['artist_id' => $w['a']->id, 'items_hash' => 'v1:palsu']]]],
            [['order_id' => $o->id + 1, 'sellers' => [['artist_id' => $w['a']->id, 'items_hash' => $this->currentHash($o->id, $w['a']->id)]]]]] as $plan) {
            $data = $this->bulkApply([$o->id], 'stamp', $plan)->assertOk()->json('data');
            $this->assertSame('changed', $data['results'][0]['reason']);
        }
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_a_stale_prior_approval_is_reconciled_and_approvable_again(): void
    {
        $w = $this->buildApprovalWorld();
        $o = $this->makeSalesOfA($w, 1)[0];
        $this->actingAs($w['userA'], 'sanctum');
        $this->bulkApply([$o->id])->assertOk();
        OrderItem::where('order_id', $o->id)->firstOrFail()->update(['qty' => 5]); // approval lama kini basi

        $this->bulkApply([$o->id])->assertOk()->assertJsonPath('data.approved_orders', 1);

        $this->assertSame(1, OrderApproval::whereNull('invalidated_at')->count());
        $this->assertSame(1, OrderApproval::whereNotNull('invalidated_at')->count(), 'riwayat lama tetap tersimpan');
    }

    public function test_a_concurrent_approval_between_preview_and_apply_is_skipped_and_a_retry_is_safe(): void
    {
        $w = $this->buildApprovalWorld();
        $o = $this->makeSalesOfA($w, 1)[0];
        $this->actingAs($w['userA'], 'sanctum');
        $plan = $this->bulkPreview([$o->id])->json('data.plan');

        $this->actingAs($w['owner'], 'sanctum'); // orang lain menyetujui lebih dulu
        $this->post("/api/v1/orders/{$o->id}/approvals", $this->stampPayload($w['a']->id, $this->currentHash($o->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($w['userA'], 'sanctum');
        $data = $this->bulkApply([$o->id], 'stamp', $plan)->assertOk()->json('data');
        $this->assertSame('already_approved', $data['results'][0]['reason']);
        $this->assertSame(1, OrderApproval::count());
    }
}
