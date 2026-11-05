<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApproveConcurrencyTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function stamp(int $orderId, int $artistId, string $hash)
    {
        return $this->post("/api/v1/orders/{$orderId}/approvals", $this->stampPayload($artistId, $hash), ['Accept' => 'application/json']);
    }

    public function test_the_second_approval_of_the_same_seller_is_refused_and_only_one_row_exists(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $hash = $this->currentHash($w['orderA']->id, $w['a']->id);

        $this->stamp($w['orderA']->id, $w['a']->id, $hash)->assertCreated();
        $this->stamp($w['orderA']->id, $w['a']->id, $hash)->assertStatus(409)->assertJsonPath('code', 'already_approved');

        $this->assertSame(1, OrderApproval::count());
    }

    public function test_a_second_user_of_the_same_seller_also_gets_already_approved(): void
    {
        $w = $this->buildApprovalWorld();
        $other = \App\Models\User::factory()->create(['role' => 'cashier', 'artist_id' => $w['a']->id]);

        $this->actingAs($w['userA'], 'sanctum');
        $hash = $this->currentHash($w['orderA']->id, $w['a']->id);
        $this->stamp($w['orderA']->id, $w['a']->id, $hash)->assertCreated();

        $this->actingAs($other, 'sanctum');
        $this->stamp($w['orderA']->id, $w['a']->id, $hash)->assertStatus(409)->assertJsonPath('code', 'already_approved');
        $this->assertSame(1, OrderApproval::count());
    }

    public function test_the_database_itself_refuses_a_second_active_row(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $this->stamp($w['orderA']->id, $w['a']->id, $this->currentHash($w['orderA']->id, $w['a']->id))->assertCreated();

        $existing = OrderApproval::firstOrFail();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('order_approvals')->insert($existing->only(['order_id', 'artist_id', 'artist_name', 'method', 'items_hash', 'approved_by', 'approver_name', 'approved_at', 'active_slot']) + ['items_snapshot' => '[]']);
    }

    public function test_a_unique_violation_that_slips_past_the_checks_maps_to_409_not_500(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $hash = $this->currentHash($w['orderA']->id, $w['a']->id);

        // Simulasikan persaingan: baris aktif muncul tepat SETELAH pemeriksaan service dan sebelum insert.
        OrderApproval::creating(function () use ($w) {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            DB::table('order_approvals')->insert([
                'order_id' => $w['orderA']->id, 'artist_id' => $w['a']->id, 'artist_name' => 'x', 'method' => 'stamp',
                'items_hash' => 'v1:rival', 'items_snapshot' => '[]', 'approved_by' => $w['owner']->id, 'approver_name' => 'rival',
                'approved_at' => now(), 'active_slot' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $this->stamp($w['orderA']->id, $w['a']->id, $hash)->assertStatus(409)->assertJsonPath('code', 'already_approved');
        } finally {
            OrderApproval::flushEventListeners(); // pendengar model tidak boleh bocor ke tes lain
        }
        // Tes berjalan di dalam transaksi; baris "saingan" ikut digulung bersama savepoint service — tak ada yang setengah jadi.
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_a_stale_hash_is_refused_and_nothing_is_written(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $this->stamp($w['orderA']->id, $w['a']->id, 'v1:'.str_repeat('0', 64))->assertStatus(409)->assertJsonPath('code', 'approval_stale');
        $this->assertSame(0, OrderApproval::count());
    }
}
