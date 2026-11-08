<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 064 (US3) — owner/admin menyetujui SEMUA seller yang menunggu atas nama seller; atomik per penjualan. */
class BulkOwnerOnBehalfTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_owner_approves_every_pending_seller_of_each_sale_on_behalf(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');

        $data = $this->bulkApply([$w['orderMulti']->id, $w['orderAB']->id])->assertOk()->json('data');

        $this->assertSame(2, $data['approved_orders']);
        $this->assertSame(5, $data['approved_sellers']); // 3 + 2
        $rows = OrderApproval::all();
        $this->assertSame([true], $rows->pluck('on_behalf')->unique()->values()->all());
        $this->assertSame([$w['owner']->id], $rows->pluck('approved_by')->unique()->values()->all());
        $this->getJson("/api/v1/orders/{$w['orderMulti']->id}/approvals")->assertJsonPath('data.status', 'full')->assertJsonPath('data.progress', '3/3');
    }

    public function test_a_failure_inside_one_sale_rolls_back_that_sale_only(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $boom = $w['orderMulti'];
        // Seller A tertulis dulu, lalu seller B meledak di penjualan yang sama → seluruh penjualan itu harus gulung balik.
        OrderApproval::creating(function ($m) use ($boom, $w) {
            if ($m->order_id === $boom->id && $m->artist_id === $w['b']->id) {
                throw new \RuntimeException('simulasi gagal');
            }
        });

        $data = $this->bulkApply([$w['orderAB']->id, $boom->id, $w['orderA']->id])->assertOk()->json('data');

        $byId = collect($data['results'])->keyBy('order_id');
        $this->assertSame('approved', $byId[$w['orderAB']->id]['status']);
        $this->assertSame('failed', $byId[$boom->id]['reason']);
        $this->assertSame('approved', $byId[$w['orderA']->id]['status']);
        $this->assertSame(0, OrderApproval::where('order_id', $boom->id)->count(), 'tak ada penjualan yang setengah disetujui');
        $this->assertStringNotContainsString('simulasi', json_encode($data), 'detail galat tak boleh bocor ke klien');
    }
}
