<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Support\OrderApprovalSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApprovalSummaryTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function approve(array $w, $order, $artist, ?string $hash = null): OrderApproval
    {
        $order->unsetRelation('items');
        $hash ??= OrderApprovalSummary::for($order)['sellers'][$artist->id]['items_hash'];

        return OrderApproval::create([
            'order_id' => $order->id, 'artist_id' => $artist->id, 'artist_name' => $artist->name,
            'method' => 'stamp', 'items_hash' => $hash, 'items_snapshot' => [],
            'approved_by' => $w['owner']->id, 'approver_name' => $w['owner']->name, 'on_behalf' => true,
            'approved_at' => now(),
        ]);
    }

    public function test_one_requirement_per_unique_seller_not_per_item(): void
    {
        $w = $this->buildApprovalWorld();
        $s = OrderApprovalSummary::for($w['orderMulti']);

        $this->assertSame(3, $s['total']);
        $this->assertCount(3, $s['sellers']);
        $this->assertCount(2, $s['sellers'][$w['a']->id]['items']);
        $this->assertCount(1, $s['sellers'][$w['b']->id]['items']);
        $this->assertSame('pending', $s['status']);
        $this->assertSame(0, $s['approved']);
    }

    public function test_status_follows_the_seller_records(): void
    {
        $w = $this->buildApprovalWorld();
        $order = $w['orderMulti'];

        $this->approve($w, $order, $w['a']);
        $this->approve($w, $order, $w['b']);
        $order->unsetRelation('approvals');
        $s = OrderApprovalSummary::for($order);
        $this->assertSame('partial', $s['status']);
        $this->assertSame([2, 1, 3], [$s['approved'], $s['pending'], $s['total']]);

        $this->approve($w, $order, $w['c']);
        $order->unsetRelation('approvals');
        $this->assertSame('full', OrderApprovalSummary::for($order)['status']);
    }

    public function test_a_single_seller_sale_is_never_partial(): void
    {
        $w = $this->buildApprovalWorld();
        $this->assertSame('pending', OrderApprovalSummary::for($w['orderA'])['status']);
        $this->approve($w, $w['orderA'], $w['a']);
        $w['orderA']->unsetRelation('approvals');
        $this->assertSame('full', OrderApprovalSummary::for($w['orderA'])['status']);
    }

    public function test_voided_sale_reports_voided(): void
    {
        $w = $this->buildApprovalWorld();
        $w['orderA']->update(['status' => 'voided']);
        $this->assertSame('voided', OrderApprovalSummary::for($w['orderA'])['status']);
    }

    public function test_an_approval_whose_lines_changed_reads_as_pending_and_stale(): void
    {
        $w = $this->buildApprovalWorld();
        $order = $w['orderAB'];
        $this->approve($w, $order, $w['a']);
        $this->approve($w, $order, $w['b']);

        OrderItem::where('order_id', $order->id)->where('artist_id', $w['b']->id)->update(['qty' => 5]);
        $order->unsetRelation('items')->unsetRelation('approvals');
        $s = OrderApprovalSummary::for($order);

        $this->assertSame('approved', $s['sellers'][$w['a']->id]['state']);
        $this->assertSame('pending', $s['sellers'][$w['b']->id]['state']);
        $this->assertTrue($s['sellers'][$w['b']->id]['stale']);
        $this->assertSame('partial', $s['status']);
    }

    public function test_hash_is_pinned_and_independent_of_ids(): void
    {
        $lines = [
            ['sku' => 'AAA-1', 'name' => 'Indomie Goreng', 'qty' => 2, 'sell_price' => '5000.00', 'discount_amount' => '0.00', 'line_total' => '10000.00'],
            ['sku' => 'AAA-2', 'name' => 'Es Teh', 'qty' => 1, 'sell_price' => '3000.00', 'discount_amount' => '0.00', 'line_total' => '3000.00'],
        ];
        $hash = OrderApprovalSummary::hashLines($lines);

        $this->assertStringStartsWith('v1:', $hash);
        $this->assertSame($hash, OrderApprovalSummary::hashLines(array_reverse($lines)), 'urutan baris tidak boleh berpengaruh');
        // Dipin: bila rumus kanonik berubah, SEMUA persetujuan tersimpan ikut tak valid — ubah hanya dengan versi baru (v2).
        $this->assertSame('v1:4fc11e447ef796017d765d31cb7d1bdb04cfe7e9b8ea35f05430c4614d30dd49', $hash);

        foreach (['qty' => 3, 'sell_price' => '5100.00', 'discount_amount' => '100.00', 'line_total' => '9900.00', 'name' => 'Lain'] as $k => $v) {
            $changed = $lines;
            $changed[0][$k] = $v;
            $this->assertNotSame($hash, OrderApprovalSummary::hashLines($changed), "perubahan {$k} harus mengubah hash");
        }
    }

    public function test_hash_ignores_row_ids_so_a_clone_keeps_approvals_valid(): void
    {
        $w = $this->buildApprovalWorld();
        $before = OrderApprovalSummary::for($w['orderA'])['sellers'][$w['a']->id]['items_hash'];

        OrderItem::where('order_id', $w['orderA']->id)->update(['variant_id' => $w['variants']['esTeh']->id]);
        $w['orderA']->unsetRelation('items');

        $this->assertSame($before, OrderApprovalSummary::for($w['orderA'])['sellers'][$w['a']->id]['items_hash']);
    }
}
