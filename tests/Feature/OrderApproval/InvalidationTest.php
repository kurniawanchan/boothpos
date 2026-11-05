<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Services\OrderApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 059 US4 — perubahan baris seorang seller membatalkan HANYA persetujuan seller itu; riwayat tak pernah hilang. */
class InvalidationTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function stamp(int $orderId, int $artistId, ?string $hash = null)
    {
        return $this->post("/api/v1/orders/{$orderId}/approvals", $this->stampPayload($artistId, $hash ?? $this->currentHash($orderId, $artistId)), ['Accept' => 'application/json']);
    }

    private function sellers(int $orderId)
    {
        return collect($this->getJson("/api/v1/orders/{$orderId}/approvals")->assertOk()->json('data.sellers'))->keyBy('artist_id');
    }

    public function test_changing_one_sellers_line_returns_only_that_seller_to_pending(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($id, $w['a']->id)->assertCreated();
        $this->stamp($id, $w['b']->id)->assertCreated()->assertJsonPath('data.status', 'full');

        OrderItem::where('order_id', $id)->where('artist_id', $w['b']->id)->update(['qty' => 4, 'line_total' => 48000]);

        $s = $this->sellers($id);
        $this->assertSame('approved', $s[$w['a']->id]['status']);
        $this->assertSame('pending', $s[$w['b']->id]['status']);
        $this->assertTrue($s[$w['b']->id]['stale']);
        $this->getJson("/api/v1/orders/{$id}/approvals")->assertJsonPath('data.status', 'partial')->assertJsonPath('data.progress', '1/2');
    }

    public function test_each_changed_field_of_a_line_invalidates(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $id = $w['orderA']->id;
        $this->stamp($id, $w['a']->id)->assertCreated();

        foreach (['qty' => 3, 'sell_price' => 7777, 'discount_amount' => 100, 'line_total' => 1234, 'name_snapshot' => 'Nama Lain'] as $field => $value) {
            $item = OrderItem::where('order_id', $id)->firstOrFail();
            $old = $item->getRawOriginal($field);
            $item->update([$field => $value]);
            $this->assertSame('pending', $this->sellers($id)[$w['a']->id]['status'], "perubahan {$field} harus membatalkan");
            $item->update([$field => $old]);
            $this->assertSame('approved', $this->sellers($id)[$w['a']->id]['status'], "dikembalikan: {$field} berlaku lagi");
        }
    }

    public function test_moving_a_line_between_sellers_invalidates_both(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderMulti']->id;
        $this->actingAs($w['owner'], 'sanctum');
        foreach (['a', 'b', 'c'] as $k) {
            $this->stamp($id, $w[$k]->id)->assertCreated();
        }

        OrderItem::where('order_id', $id)->where('name_snapshot', 'like', 'Indomie%')->update(['artist_id' => $w['b']->id]);

        $s = $this->sellers($id);
        $this->assertSame('pending', $s[$w['a']->id]['status']);
        $this->assertSame('pending', $s[$w['b']->id]['status']);
        $this->assertSame('approved', $s[$w['c']->id]['status']); // tak terusik
        $this->getJson("/api/v1/orders/{$id}/approvals")->assertJsonPath('data.progress', '1/3');
    }

    public function test_removing_a_sellers_last_line_drops_the_requirement_but_keeps_the_record(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($id, $w['a']->id)->assertCreated();
        $this->stamp($id, $w['b']->id)->assertCreated();

        OrderItem::where('order_id', $id)->where('artist_id', $w['b']->id)->delete();

        $this->getJson("/api/v1/orders/{$id}/approvals")->assertJsonPath('data.total', 1)->assertJsonPath('data.status', 'full');
        $this->assertSame(2, OrderApproval::where('order_id', $id)->count(), 'riwayat tetap ada');

        app(OrderApprovalService::class)->reconcile($w['orderAB']->fresh(), $w['owner']);
        $b = OrderApproval::where('artist_id', $w['b']->id)->firstOrFail();
        $this->assertNotNull($b->invalidated_at);
        $this->assertNull($b->active_slot);
    }

    public function test_a_stale_request_is_refused_but_the_invalidation_is_kept_and_logged(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $oldHash = $this->currentHash($id, $w['b']->id);
        $this->stamp($id, $w['b']->id, $oldHash)->assertCreated();

        OrderItem::where('order_id', $id)->where('artist_id', $w['b']->id)->update(['qty' => 9]);

        $this->stamp($id, $w['b']->id, $oldHash)->assertStatus(409)->assertJsonPath('code', 'approval_stale');

        $old = OrderApproval::firstOrFail();
        $this->assertNotNull($old->invalidated_at, 'pembatalan tidak ikut digulung oleh penolakan');
        $this->assertSame('items_changed', $old->invalidation_reason);
        $this->assertNull($old->active_slot);
        $this->assertSame(1, ActivityLog::where('action', 'order_approval_invalidated')->count());
        $this->assertSame(1, OrderApproval::count(), 'tidak ada baris baru dari permintaan yang ditolak');
    }

    public function test_reapproving_creates_a_new_cycle_and_keeps_the_old_row(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($id, $w['b']->id)->assertCreated();
        OrderItem::where('order_id', $id)->where('artist_id', $w['b']->id)->update(['qty' => 2]);

        $this->stamp($id, $w['b']->id)->assertCreated(); // hash baru dari GET

        $rows = OrderApproval::where('artist_id', $w['b']->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->invalidated_at);
        $this->assertNull($rows[0]->active_slot);
        $this->assertNull($rows[1]->invalidated_at);
        $this->assertSame(1, $rows[1]->active_slot);
        $this->assertNotSame($rows[0]->items_hash, $rows[1]->items_hash);
        $this->assertSame('approved', $this->sellers($id)[$w['b']->id]['status']);
    }

    public function test_reconcile_is_idempotent_and_logs_once(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($id, $w['b']->id)->assertCreated();
        OrderItem::where('order_id', $id)->where('artist_id', $w['b']->id)->update(['qty' => 5]);

        $service = app(OrderApprovalService::class);
        $this->assertSame(1, $service->reconcile($w['orderAB']->fresh(), $w['owner']));
        $this->assertSame(0, $service->reconcile($w['orderAB']->fresh(), $w['owner']));
        $this->assertSame(1, ActivityLog::where('action', 'order_approval_invalidated')->count());
    }

    public function test_reading_never_writes(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($id, $w['b']->id)->assertCreated();
        OrderItem::where('order_id', $id)->where('artist_id', $w['b']->id)->update(['qty' => 5]);

        $this->sellers($id);
        $this->getJson('/api/v1/reports/sales')->assertOk();
        $this->getJson("/api/v1/orders/{$id}")->assertOk();

        $row = OrderApproval::firstOrFail();
        $this->assertNull($row->invalidated_at);
        $this->assertSame(1, $row->active_slot);
        $this->assertSame(0, ActivityLog::where('action', 'order_approval_invalidated')->count());
    }

    public function test_a_voided_sale_keeps_every_record_untouched_and_accepts_no_approval(): void
    {
        $w = $this->buildApprovalWorld();
        $id = $w['orderAB']->id;
        $this->actingAs($w['owner'], 'sanctum');
        $this->stamp($id, $w['a']->id)->assertCreated();

        $w['orderAB']->update(['status' => 'voided']);
        OrderItem::where('order_id', $id)->where('artist_id', $w['a']->id)->update(['qty' => 7]);

        $this->stamp($id, $w['b']->id, 'v1:x')->assertStatus(409)->assertJsonPath('code', 'order_voided');
        $this->assertSame(0, app(OrderApprovalService::class)->reconcile($w['orderAB']->fresh(), $w['owner']));

        $row = OrderApproval::firstOrFail();
        $this->assertNull($row->invalidated_at);
        $this->assertSame(1, OrderApproval::count());
    }
}
