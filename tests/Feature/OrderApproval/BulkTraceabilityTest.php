<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 064 (US5) — persetujuan massal = persetujuan biasa + identitas batch; tetap append-only. */
class BulkTraceabilityTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_bulk_ref_logs_and_history_trace_each_batch(): void
    {
        $w = $this->buildApprovalWorld();
        [$a1, $a2, $single] = $this->makeSalesOfA($w, 3);
        $this->actingAs($w['userA'], 'sanctum');

        $first = $this->bulkApply([$a1->id, $a2->id])->assertOk()->json('data');
        $this->post("/api/v1/orders/{$single->id}/approvals", $this->stampPayload($w['a']->id, $this->currentHash($single->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();
        $second = $this->bulkApply([$w['orderMulti']->id])->assertOk()->json('data');

        $this->assertNotSame($first['bulk_ref'], $second['bulk_ref']);
        $this->assertSame([$first['bulk_ref']], OrderApproval::whereIn('order_id', [$a1->id, $a2->id])->pluck('bulk_ref')->unique()->values()->all());
        $this->assertNull(OrderApproval::where('order_id', $single->id)->value('bulk_ref'), 'persetujuan tunggal tanpa bulk_ref');

        $perApproval = ActivityLog::where('action', 'order_approved')->get();
        $this->assertSame(4, $perApproval->count());
        $this->assertSame(3, $perApproval->filter(fn ($l) => isset($l->new_values['bulk_ref']))->count());

        $agg = ActivityLog::where('action', 'order_bulk_approved')->orderBy('id')->get();
        $this->assertCount(2, $agg, 'satu baris agregat per batch yang diterapkan');
        $this->assertSame($first['bulk_ref'], $agg[0]->new_values['bulk_ref']);
        $this->assertSame(2, $agg[0]->new_values['approved_orders']);
        $this->assertSame('stamp', $agg[0]->new_values['method']);
        $this->assertNull($agg[0]->entity_id);

        // Riwayat: owner/admin melihat via_bulk; pengguna lain tak melihat history sama sekali.
        $this->actingAs($w['owner'], 'sanctum');
        $hist = collect($this->getJson("/api/v1/orders/{$a1->id}/approvals")->assertOk()->json('data.history'));
        $this->assertTrue($hist->every(fn ($h) => $h['via_bulk'] === true));
        $singleHist = $this->getJson("/api/v1/orders/{$single->id}/approvals")->json('data.history');
        $this->assertFalse($singleHist[0]['via_bulk']);
        $this->actingAs($w['userA'], 'sanctum');
        $this->assertArrayNotHasKey('history', $this->getJson("/api/v1/orders/{$a1->id}/approvals")->json('data'));
    }

    public function test_skipped_sales_have_no_rows_or_approved_logs_and_a_rejected_batch_writes_no_aggregate(): void
    {
        $w = $this->buildApprovalWorld();
        $o = $this->makeSalesOfA($w, 1)[0];
        $this->actingAs($w['cashier'], 'sanctum');
        $this->bulkApply([$o->id])->assertOk()->assertJsonPath('data.approved_orders', 0);
        $this->bulkApply([$o->id], 'draw')->assertStatus(422);

        $this->assertSame(0, OrderApproval::count());
        $this->assertSame(0, ActivityLog::whereIn('action', ['order_approved', 'order_bulk_approved'])->count());
    }

    public function test_there_is_still_no_way_to_edit_or_delete_an_approval(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $o = $this->makeSalesOfA($w, 1)[0];
        $this->bulkApply([$o->id])->assertOk();
        $id = OrderApproval::firstOrFail()->id;

        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            foreach (["/api/v1/orders/{$o->id}/approvals/{$id}", '/api/v1/orders/approvals/bulk', "/api/v1/orders/approvals/bulk/{$id}"] as $uri) {
                $this->assertContains($this->$verb($uri)->status(), [404, 405], "{$verb} {$uri} tidak boleh ada");
            }
        }
        $this->assertSame(1, OrderApproval::count());
    }
}
