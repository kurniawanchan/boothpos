<?php

namespace Tests\Feature\PreorderBulk;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 065 US5 — penanda progres (invoice terkirim / pengiriman berjalan) massal. */
class BulkDispatchTest extends TestCase
{
    use PreorderBulkFixture, RefreshDatabase;

    public function test_shipping_marker_only_changes_eligible_mail_orders_and_keeps_dates_when_already_done(): void
    {
        $w = $this->buildBulkWorld();
        $mail = $this->bulkPreorder($w, 'ordered', 'courier');
        $already = $this->bulkPreorder($w, 'ordered', 'courier');
        $pickup = $this->bulkPreorder($w, 'ordered', 'pickup');
        $cancelled = $this->bulkPreorder($w, 'cancelled', 'courier');
        $this->actingAs($w['owner'], 'sanctum');
        $this->patchJson("/api/v1/preorders/{$already->id}/dispatch-status", ['dispatch_status' => 'shipping'])->assertOk();
        $stamp = $already->fresh()->shipping_at;
        $this->travel(2)->hours();

        $res = $this->bulkCall('dispatch', 'apply', [$mail, $already, $pickup, $cancelled], ['dispatch_status' => 'shipping'])
            ->assertOk()->json('data');

        $r = collect($res['results'])->keyBy('preorder_id');
        $this->assertSame('succeeded', $r[$mail->id]['status']);
        $this->assertSame('already_done', $r[$already->id]['reason']);
        $this->assertSame('not_mail_order', $r[$pickup->id]['reason']);
        $this->assertSame('cancelled', $r[$cancelled->id]['reason']);
        $this->assertSame('shipping', $mail->fresh()->dispatch_status);
        $this->assertTrue($already->fresh()->shipping_at->equalTo($stamp));   // tanggal tak bergeser
        $this->assertSame('pending', $pickup->fresh()->dispatch_status);
    }

    public function test_marker_dates_and_side_effects_equal_the_individual_action(): void
    {
        $w = $this->buildBulkWorld();
        $viaBulk = $this->bulkPreorder($w, 'ordered', 'courier');
        $viaSingle = $this->bulkPreorder($w, 'ordered', 'courier');
        $this->actingAs($w['owner'], 'sanctum');

        $this->bulkCall('dispatch', 'apply', [$viaBulk], ['dispatch_status' => 'invoice_sent'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$viaSingle->id}/dispatch-status", ['dispatch_status' => 'invoice_sent'])->assertOk();

        $a = $viaBulk->fresh();
        $b = $viaSingle->fresh();
        $this->assertSame($b->dispatch_status, $a->dispatch_status);
        $this->assertNotNull($a->invoice_sent_at);
        $this->assertNull($a->shipping_at);
        $this->assertSame($b->status, $a->status);
        $this->assertSame((string) $b->paid_amount, (string) $a->paid_amount);

        $this->bulkCall('dispatch', 'apply', [$viaBulk], ['dispatch_status' => 'pending'])->assertOk()->assertJsonPath('data.succeeded', 1);
        $this->assertNull($viaBulk->fresh()->invoice_sent_at);
        $row = ActivityLog::where('action', 'preorder_bulk_dispatch')->latest('id')->first();
        $this->assertSame('invoice_sent', $row->old_values['dispatch_status']);
        $this->assertSame('pending', $row->new_values['dispatch_status']);
    }

    public function test_the_removed_payment_and_shipment_bulk_routes_do_not_exist(): void
    {
        $w = $this->buildBulkWorld();
        $po = $this->bulkPreorder($w, 'ordered');
        $this->actingAs($w['owner'], 'sanctum');

        foreach (['payment', 'shipment'] as $action) {
            foreach (['preview', 'apply'] as $phase) {
                $this->bulkCall($action, $phase, [$po], ['method' => 'cash'])->assertNotFound();
            }
        }
    }
}
