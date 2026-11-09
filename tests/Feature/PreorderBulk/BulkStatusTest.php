<?php

namespace Tests\Feature\PreorderBulk;

use App\Models\ActivityLog;
use App\Models\Preorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 065 US2 — ubah status massal: layak pindah persis seperti aksi individual, sisanya dilewati dengan alasan. */
class BulkStatusTest extends TestCase
{
    use PreorderBulkFixture, RefreshDatabase;

    public function test_a_mixed_batch_moves_only_valid_transitions_and_counts_add_up(): void
    {
        $w = $this->buildBulkWorld();
        $dp = $this->bulkPreorder($w, 'dp_paid');
        $dp2 = $this->bulkPreorder($w, 'dp_paid');
        $ordered = $this->bulkPreorder($w, 'ordered');
        $done = $this->bulkPreorder($w, 'handed_over');
        $this->actingAs($w['owner'], 'sanctum');

        $res = $this->bulkCall('status', 'apply', [$dp, $ordered, $done, $dp2, 99999], ['status' => 'arrived'])
            ->assertOk()->json('data');

        $this->assertSame(5, $res['selected']);
        $this->assertSame(2, $res['succeeded']);
        $this->assertSame(3, $res['skipped']);
        $this->assertSame(0, $res['failed']);
        $this->assertCount(5, $res['results']);
        $this->assertSame([$dp->id, $ordered->id, $done->id, $dp2->id, 99999], array_column($res['results'], 'preorder_id'));
        $this->assertSame(['invalid_transition' => 2, 'not_found' => 1], $res['skipped_by_reason']);
        foreach ($res['results'] as $r) {
            if ($r['status'] !== 'succeeded') {
                $this->assertNotEmpty($r['reason']);
                $this->assertNotEmpty($r['message']);
            }
        }

        $this->assertSame('arrived', $dp->fresh()->status);
        $this->assertSame('arrived', $dp2->fresh()->status);
        $this->assertSame('ordered', $ordered->fresh()->status);   // tak pernah dipaksa
        $this->assertSame('handed_over', $done->fresh()->status);
    }

    public function test_bulk_result_equals_the_individual_action_for_the_same_pre_order(): void
    {
        $w = $this->buildBulkWorld();
        $viaBulk = $this->bulkPreorder($w, 'dp_paid');
        $viaSingle = $this->bulkPreorder($w, 'dp_paid');
        $this->actingAs($w['owner'], 'sanctum');

        $this->bulkCall('status', 'apply', [$viaBulk], ['status' => 'arrived'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$viaSingle->id}/status", ['status' => 'arrived'])->assertOk();

        $a = $viaBulk->fresh();
        $b = $viaSingle->fresh();
        $this->assertSame($b->status, $a->status);
        $this->assertSame((string) $b->paid_amount, (string) $a->paid_amount);
    }

    public function test_a_fully_paid_pre_order_settles_on_arrival_exactly_like_the_individual_action(): void
    {
        $w = $this->buildBulkWorld();
        $po = $this->bulkPreorder($w, 'dp_paid', 'pickup', null, 100000);
        $this->actingAs($w['owner'], 'sanctum');

        $this->bulkCall('status', 'apply', [$po], ['status' => 'arrived'])->assertOk()->assertJsonPath('data.succeeded', 1);

        $this->assertSame('settled', $po->fresh()->status);
    }

    public function test_handed_over_skips_unpaid_and_short_stock_while_the_rest_proceed(): void
    {
        $w = $this->buildBulkWorld();
        $ok = $this->bulkPreorder($w, 'settled');
        $unpaid = $this->bulkPreorder($w, 'settled');
        $unpaid->update(['paid_amount' => 40000]);   // settled tetapi belum lunas (mis. pembayaran dihapus)
        $short = $this->bulkPreorder($w, 'settled');
        $w['variant']->update(['current_stock' => 1]); // cukup untuk satu serah terima saja
        $this->actingAs($w['owner'], 'sanctum');

        $res = $this->bulkCall('status', 'apply', [$ok, $unpaid, $short], ['status' => 'handed_over'])->assertOk()->json('data');

        $byId = collect($res['results'])->keyBy('preorder_id');
        $this->assertSame('succeeded', $byId[$ok->id]['status']);
        $this->assertSame('not_fully_paid', $byId[$unpaid->id]['reason']);
        $this->assertSame('insufficient_stock', $byId[$short->id]['reason']);
        $this->assertSame([1, 2, 0], [$res['succeeded'], $res['skipped'], $res['failed']]);

        $this->assertSame('handed_over', $ok->fresh()->status);
        $this->assertSame('settled', $unpaid->fresh()->status);
        $this->assertSame('settled', $short->fresh()->status);
        $this->assertSame(0, $w['variant']->fresh()->current_stock);
        // Hanya SATU pergerakan stok serah terima (yang gagal tak meninggalkan jejak).
        $this->assertSame(1, \App\Models\StockMovement::where('type', 'preorder_handover')->count());
    }

    public function test_cancelling_requires_one_reason_and_stores_it_on_every_pre_order(): void
    {
        $w = $this->buildBulkWorld();
        $a = $this->bulkPreorder($w, 'ordered');
        $b = $this->bulkPreorder($w, 'dp_paid');
        $this->actingAs($w['owner'], 'sanctum');

        $this->bulkCall('status', 'apply', [$a, $b], ['status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('ordered', $a->fresh()->status);

        $this->bulkCall('status', 'apply', [$a, $b], ['status' => 'cancelled', 'cancel_reason' => 'Event batal'])
            ->assertOk()->assertJsonPath('data.succeeded', 2);
        $this->assertSame('Event batal', $a->fresh()->cancel_reason);
        $this->assertSame('Event batal', $b->fresh()->cancel_reason);
    }

    public function test_preview_offers_only_reachable_targets_with_counts_and_writes_nothing(): void
    {
        $w = $this->buildBulkWorld();
        $a = $this->bulkPreorder($w, 'ordered');
        $b = $this->bulkPreorder($w, 'dp_paid');
        $this->actingAs($w['owner'], 'sanctum');

        $logsBefore = ActivityLog::count();
        $opts = $this->bulkCall('status', 'preview', [$a, $b])->assertOk()->json('data.status_options');
        $byStatus = collect($opts)->pluck('count', 'status')->all();

        $this->assertSame(['dp_paid' => 1, 'arrived' => 1, 'cancelled' => 2], $byStatus);

        $prev = $this->bulkCall('status', 'preview', [$a, $b], ['status' => 'arrived'])->assertOk()->json('data');
        $this->assertSame(1, $prev['eligible']);
        $this->assertSame(1, count($prev['skipped']));
        $this->assertSame(2, $prev['eligible'] + count($prev['skipped']));
        $this->assertSame('ordered', $a->fresh()->status);
        $this->assertSame($logsBefore, ActivityLog::count());
    }

    public function test_each_changed_pre_order_gets_its_own_bulk_audit_row_and_one_aggregate_row(): void
    {
        $w = $this->buildBulkWorld();
        $a = $this->bulkPreorder($w, 'dp_paid');
        $skip = $this->bulkPreorder($w, 'ordered');
        $this->actingAs($w['owner'], 'sanctum');

        $res = $this->bulkCall('status', 'apply', [$a, $skip], ['status' => 'arrived'])->assertOk()->json('data');

        $rows = ActivityLog::where('action', 'preorder_bulk_status')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows[0]->entity_id);
        $this->assertSame('Preorder', $rows[0]->entity_type);
        $this->assertSame('dp_paid', $rows[0]->old_values['status']);
        $this->assertSame('arrived', $rows[0]->new_values['status']);
        $this->assertSame($res['bulk_ref'], $rows[0]->new_values['bulk_ref']);
        $this->assertSame($w['owner']->id, $rows[0]->user_id);

        $agg = ActivityLog::where('action', 'preorder_bulk_operation')->get();
        $this->assertCount(1, $agg);
        $this->assertSame(2, $agg[0]->new_values['selected']);
        $this->assertSame(['invalid_transition' => 1], $agg[0]->new_values['skipped_by_reason']);
        $this->assertSame(0, ActivityLog::where('action', 'preorder_bulk_status')->where('entity_id', $skip->id)->count());
    }
}
