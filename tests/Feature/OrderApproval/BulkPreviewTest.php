<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 064 (US1) — pratinjau hanya membaca: angka konfirmasi dihitung server, plan memuat hash terkini. */
class BulkPreviewTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_preview_reports_numbers_sellers_amount_skips_and_a_plan_without_writing_anything(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        // A sudah menyetujui orderA sendiri → dilewati; orderAB (A+B) dan orderMulti (A+B+C) layak untuk seller A saja.
        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", $this->stampPayload($w['a']->id, $this->currentHash($w['orderA']->id, $w['a']->id)), ['Accept' => 'application/json'])->assertCreated();
        $logsBefore = ActivityLog::count();
        $rowsBefore = OrderApproval::count();

        $ids = [$w['orderA']->id, $w['orderAB']->id, $w['orderMulti']->id];
        $data = $this->bulkPreview($ids)->assertOk()->json('data');

        $this->assertSame(3, $data['selected']);
        $this->assertSame(2, $data['eligible_orders']);
        $this->assertSame(2, $data['approvals']);
        // orderAB: Es Teh 3.000 (A); orderMulti: Indomie 5.000 + Es Teh 3.000 (A) → hanya bagian seller A.
        $this->assertSame('11000.00', $data['amount']);
        $this->assertSame([['artist_id' => $w['a']->id, 'artist_name' => $w['a']->name, 'approvals' => 2, 'amount' => '11000.00']], $data['sellers']);
        $this->assertSame([['order_id' => $w['orderA']->id, 'order_number' => $w['orderA']->order_number, 'reason' => 'already_approved', 'message' => __('approvals.skip_reasons.already_approved')]], $data['skipped']);
        $this->assertSame($data['selected'], $data['eligible_orders'] + count($data['skipped']));

        $plan = collect($data['plan'])->keyBy('order_id');
        $this->assertSame([$w['orderAB']->id, $w['orderMulti']->id], $plan->keys()->all());
        $this->assertSame($this->currentHash($w['orderAB']->id, $w['a']->id), $plan[$w['orderAB']->id]['sellers'][0]['items_hash']);

        $this->assertSame($rowsBefore, OrderApproval::count());
        $this->assertSame($logsBefore, ActivityLog::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_preview_with_nothing_eligible_says_so(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['cashier'], 'sanctum'); // tanpa seller → tak ada yang bisa disetujui

        $data = $this->bulkPreview([$w['orderA']->id])->assertOk()->json('data');
        $this->assertSame(0, $data['approvals']);
        $this->assertSame(0, $data['eligible_orders']);
        $this->assertSame('no_approvable_seller', $data['skipped'][0]['reason']);
        $this->assertSame([], $data['plan']);
    }
}
