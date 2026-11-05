<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesListApprovalTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function row(array $rows, int $orderId): array
    {
        return collect($rows)->firstWhere('id', $orderId);
    }

    private function approve(int $orderId, int $artistId, $as): void
    {
        $this->actingAs($as, 'sanctum');
        $this->post("/api/v1/orders/{$orderId}/approvals", $this->stampPayload($artistId, $this->currentHash($orderId, $artistId)), ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_every_row_carries_the_approval_summary(): void
    {
        $w = $this->buildApprovalWorld();
        $this->approve($w['orderMulti']->id, $w['a']->id, $w['userA']);
        $this->approve($w['orderMulti']->id, $w['b']->id, $w['owner']);
        $this->approve($w['orderA']->id, $w['a']->id, $w['userA']);

        $this->actingAs($w['owner'], 'sanctum');
        $rows = $this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions');

        $multi = $this->row($rows, $w['orderMulti']->id)['approval'];
        $this->assertSame(['partial', 3, 2, 1], [$multi['status'], $multi['total'], $multi['approved'], $multi['pending']]);
        $sellers = collect($multi['sellers'])->keyBy('artist_id');
        $this->assertSame('approved', $sellers[$w['a']->id]['status']);
        $this->assertSame($w['userA']->name, $sellers[$w['a']->id]['approver_name']);
        $this->assertSame('stamp', $sellers[$w['a']->id]['method']);
        $this->assertNotNull($sellers[$w['a']->id]['approved_at']);
        $this->assertTrue($sellers[$w['b']->id]['on_behalf']);
        $this->assertSame('pending', $sellers[$w['c']->id]['status']);
        $this->assertNull($sellers[$w['c']->id]['approver_name']);

        $this->assertSame('full', $this->row($rows, $w['orderA']->id)['approval']['status']);
        $this->assertSame('pending', $this->row($rows, $w['orderAB']->id)['approval']['status']);
    }

    public function test_the_list_never_carries_hashes_urls_or_images(): void
    {
        $w = $this->buildApprovalWorld();
        $this->approve($w['orderA']->id, $w['a']->id, $w['userA']);

        $this->actingAs($w['owner'], 'sanctum');
        $approval = $this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions.0.approval');
        $flat = json_encode($approval);

        $this->assertStringNotContainsString('v1:', $flat);
        $this->assertStringNotContainsString('image', $flat);
        $this->assertStringNotContainsString('items_hash', $flat);
        $this->assertStringNotContainsString('order-approvals', $flat);
    }

    public function test_voided_rows_read_voided_and_legacy_rows_read_pending(): void
    {
        $w = $this->buildApprovalWorld();
        $w['orderAB']->update(['status' => 'voided']);

        $this->actingAs($w['owner'], 'sanctum');
        $rows = $this->getJson('/api/v1/reports/sales?include_voided=1')->assertOk()->json('transactions');

        $this->assertSame('voided', $this->row($rows, $w['orderAB']->id)['approval']['status']);
        $this->assertSame('pending', $this->row($rows, $w['orderA']->id)['approval']['status']); // lama, tanpa baris
        $this->assertSame(0, \App\Models\OrderApproval::count(), 'membaca daftar tidak boleh menulis apa pun');
    }

    public function test_a_stale_approval_shows_pending_with_the_stale_flag_in_the_list(): void
    {
        $w = $this->buildApprovalWorld();
        $this->approve($w['orderAB']->id, $w['b']->id, $w['owner']);
        OrderItem::where('order_id', $w['orderAB']->id)->where('artist_id', $w['b']->id)->update(['qty' => 9]);

        $this->actingAs($w['owner'], 'sanctum');
        $row = $this->row($this->getJson('/api/v1/reports/sales')->json('transactions'), $w['orderAB']->id)['approval'];
        $b = collect($row['sellers'])->firstWhere('artist_id', $w['b']->id);

        $this->assertSame('pending', $b['status']);
        $this->assertTrue($b['stale']);
        $this->assertSame('pending', $row['status']);
    }

    public function test_a_seller_scoped_viewer_still_gets_the_whole_sale_status(): void
    {
        $w = $this->buildApprovalWorld();
        $this->approve($w['orderMulti']->id, $w['b']->id, $w['userB']);

        $this->actingAs($w['userA'], 'sanctum');
        $row = $this->row($this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions'), $w['orderMulti']->id)['approval'];

        $this->assertSame(3, $row['total']);
        $this->assertSame('partial', $row['status']);
        $this->assertSame('Seller Approval Beta', collect($row['sellers'])->firstWhere('artist_id', $w['b']->id)['name']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $this->getJson('/api/v1/reports/sales')->assertOk(); // pemanasan

        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/reports/sales')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $few = $count();

        foreach (range(1, 6) as $_) {
            $w['sell']([$w['variants']['indomie'], $w['variants']['kopi'], $w['variants']['roti']]);
        }
        $many = $count();

        $this->assertSame($few, $many, "3 baris={$few} kueri, 9 baris={$many} kueri");
    }
}
