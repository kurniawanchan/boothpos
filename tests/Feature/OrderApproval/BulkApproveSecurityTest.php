<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 064 — dinding server: izin, kepemilikan seller, id rekayasa, dan satu baris aktif walau diulang. */
class BulkApproveSecurityTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_a_role_without_the_approve_action_is_refused_on_both_endpoints_and_nothing_changes(): void
    {
        $w = $this->buildApprovalWorld();
        $role = Role::create(['name' => 'Seller Lihat Saja', 'menu_keys' => ['sales'], 'menu_actions' => ['sales' => ['void']], 'is_system' => false]);
        $limited = User::factory()->create(['role_id' => $role->id, 'artist_id' => $w['a']->id]);
        $this->actingAs($limited, 'sanctum');

        $this->postJson('/api/v1/orders/approvals/bulk/preview', ['order_ids' => [$w['orderA']->id]])->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
        $this->bulkApply([$w['orderA']->id], 'stamp', [])->assertStatus(403)->assertJsonPath('code', 'action_not_allowed');
        $this->assertSame(0, OrderApproval::count());
        $this->assertSame(0, ActivityLog::whereIn('action', ['order_approved', 'order_bulk_approved'])->count());
    }

    public function test_a_user_without_a_seller_who_is_not_admin_approves_nothing(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['cashier'], 'sanctum');

        $data = $this->bulkApply([$w['orderA']->id, $w['orderMulti']->id])->assertOk()->json('data');

        $this->assertSame(0, $data['approved_sellers']);
        $this->assertSame(['no_approvable_seller' => 2], (array) $data['skipped_by_reason']);
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_crafted_ids_of_invisible_sales_look_exactly_like_missing_ones(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userB'], 'sanctum'); // orderA hanya berisi item seller A

        $foreign = $this->bulkPreview([$w['orderA']->id, 987654])->assertOk()->json('data.skipped');

        $this->assertSame('not_found', $foreign[0]['reason']);
        $this->assertNull($foreign[0]['order_number'], 'nomor penjualan yang tak terlihat tak boleh bocor');
        $this->assertSame(array_diff_key($foreign[0], ['order_id' => 1]), array_diff_key($foreign[1], ['order_id' => 1]));
    }

    public function test_repeated_and_back_to_back_applies_leave_one_active_row_per_seller(): void
    {
        $w = $this->buildApprovalWorld();
        $ids = array_map(fn ($o) => $o->id, $this->makeSalesOfA($w, 3));
        $this->actingAs($w['userA'], 'sanctum');
        $plan = $this->bulkPreview($ids)->json('data.plan');

        $first = $this->bulkApply($ids, 'stamp', $plan)->assertOk()->json('data');
        $second = $this->bulkApply($ids, 'stamp', $plan)->assertOk()->json('data');

        $this->assertSame(3, $first['approved_orders'] + $second['approved_orders']);
        $this->assertSame(3, OrderApproval::whereNull('invalidated_at')->count());
        $this->assertSame(3, OrderApproval::selectRaw('COUNT(DISTINCT order_id, artist_id) c')->value('c'));
    }
}
