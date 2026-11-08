<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 064 (US1) — stempel massal: satu persetujuan per seller yang layak, satu bulk_ref, hitungan menjumlah. */
class BulkApproveStampTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_stamp_bulk_records_one_active_approval_per_sale_with_a_shared_bulk_ref(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $orders = $this->makeSalesOfA($w, 5);
        $ids = array_map(fn ($o) => $o->id, $orders);
        $this->actingAs($w['userA'], 'sanctum');

        $res = $this->bulkApply($ids)->assertOk();
        $data = $res->json('data');

        $this->assertSame(5, $data['selected']);
        $this->assertSame(5, $data['approved_orders']);
        $this->assertSame(5, $data['approved_sellers']);
        $this->assertSame(0, $data['skipped_total']);
        $this->assertSame($ids, array_column($data['results'], 'order_id')); // urutan permintaan

        $rows = OrderApproval::all();
        $this->assertCount(5, $rows);
        $this->assertSame([$data['bulk_ref']], $rows->pluck('bulk_ref')->unique()->values()->all());
        $this->assertSame(['stamp'], $rows->pluck('method')->unique()->values()->all());
        $this->assertSame([false], $rows->pluck('on_behalf')->unique()->values()->all());
        $this->assertSame([$w['userA']->id], $rows->pluck('approved_by')->unique()->values()->all());
        $this->assertSame([null], $rows->pluck('image_path')->unique()->values()->all());
        $this->assertSame([], Storage::disk('local')->allFiles());

        foreach ($ids as $id) {
            $this->getJson("/api/v1/orders/{$id}/approvals")->assertJsonPath('data.status', 'full')->assertJsonPath('data.progress', '1/1');
        }
        $this->assertSame(5, ActivityLog::where('action', 'order_approved')->count());
    }

    public function test_already_approved_and_voided_sales_are_skipped_and_untouched_and_counts_add_up(): void
    {
        $w = $this->buildApprovalWorld();
        [$fresh, $done, $voided] = $this->makeSalesOfA($w, 3);
        $this->actingAs($w['userA'], 'sanctum');

        $this->post("/api/v1/orders/{$done->id}/approvals", $this->stampPayload($w['a']->id, $this->currentHash($done->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();
        $original = OrderApproval::where('order_id', $done->id)->firstOrFail();
        $voided->update(['status' => 'voided']);

        $ids = [$fresh->id, $done->id, $voided->id];
        $data = $this->bulkApply($ids)->assertOk()->json('data');

        $this->assertSame(1, $data['approved_orders']);
        $this->assertSame(2, $data['skipped_total']);
        $this->assertSame(['already_approved' => 1, 'voided' => 1], (array) $data['skipped_by_reason']);
        $this->assertSame($data['selected'], $data['approved_orders'] + $data['skipped_total']);
        $this->assertSame(['approved', 'skipped', 'skipped'], array_column($data['results'], 'status'));
        $this->assertSame(['already_approved', 'voided'], array_column(array_slice($data['results'], 1), 'reason'));

        $this->assertSame(2, OrderApproval::count()); // yang lama + satu baru
        $this->assertSame($original->id, OrderApproval::where('order_id', $done->id)->firstOrFail()->id);
        $this->assertNull($original->fresh()->bulk_ref);
        $this->assertSame(0, OrderApproval::where('order_id', $voided->id)->count());
    }

    public function test_repeating_the_identical_request_approves_nothing_new(): void
    {
        $w = $this->buildApprovalWorld();
        $ids = array_map(fn ($o) => $o->id, $this->makeSalesOfA($w, 3));
        $this->actingAs($w['userA'], 'sanctum');

        $plan = $this->bulkPreview($ids)->json('data.plan');
        $this->bulkApply($ids, 'stamp', $plan)->assertOk()->assertJsonPath('data.approved_orders', 3);
        $again = $this->bulkApply($ids, 'stamp', $plan)->assertOk()->json('data');

        $this->assertSame(0, $again['approved_orders']);
        $this->assertSame(3, $again['skipped_total']);
        $this->assertSame(['already_approved' => 3], (array) $again['skipped_by_reason']);
        $this->assertSame(3, OrderApproval::count());
    }
}
