<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApproveTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function post_(int $orderId, array $data)
    {
        return $this->post("/api/v1/orders/{$orderId}/approvals", $data, ['Accept' => 'application/json']);
    }

    public function test_stamp_approval_records_who_when_and_how_without_a_file(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $hash = $this->currentHash($w['orderA']->id, $w['a']->id);

        $this->post_($w['orderA']->id, $this->stampPayload($w['a']->id, $hash))
            ->assertCreated()
            ->assertJsonPath('data.status', 'full')
            ->assertJsonPath('data.progress', '1/1')
            ->assertJsonPath('data.sellers.0.status', 'approved')
            ->assertJsonPath('data.sellers.0.approval.method', 'stamp')
            ->assertJsonPath('data.sellers.0.approval.approver_id', $w['userA']->id)
            ->assertJsonPath('data.sellers.0.approval.on_behalf', false)
            ->assertJsonPath('data.sellers.0.approval.has_image', false);

        $row = OrderApproval::firstOrFail();
        $this->assertSame($w['a']->id, $row->artist_id);
        $this->assertSame($hash, $row->items_hash);
        $this->assertSame(1, $row->active_slot);
        $this->assertNull($row->image_path);
        $this->assertStringStartsWith('Indomie Goreng', $row->items_snapshot['lines'][0]['name']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_draw_and_upload_store_a_private_uuid_named_file(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();

        $this->actingAs($w['userA'], 'sanctum');
        $this->post_($w['orderA']->id, ['method' => 'draw', 'image' => UploadedFile::fake()->image('signature.png')] + $this->stampPayload($w['a']->id, $this->currentHash($w['orderA']->id, $w['a']->id)))
            ->assertCreated()->assertJsonPath('data.sellers.0.approval.has_image', true);

        $this->actingAs($w['userA'], 'sanctum');
        $this->post_($w['orderAB']->id, ['method' => 'upload', 'image' => UploadedFile::fake()->image('ttd.jpg')] + $this->stampPayload($w['a']->id, $this->currentHash($w['orderAB']->id, $w['a']->id)))
            ->assertCreated();

        $rows = OrderApproval::orderBy('id')->get();
        $this->assertSame(['draw', 'upload'], $rows->pluck('method')->all());
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('#^order-approvals/[0-9a-f-]{36}\.(png|jpg)$#', $row->image_path);
            Storage::disk('local')->assertExists($row->image_path);
            $this->assertStringNotContainsString('signature', $row->image_path); // nama klien tak dipakai
        }
        $this->assertSame('image/png', $rows[0]->image_mime);
        $this->assertSame('image/jpeg', $rows[1]->image_mime);
    }

    public function test_viewing_never_approves(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $this->getJson("/api/v1/orders/{$w['orderA']->id}/approvals")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson("/api/v1/orders/{$w['orderA']->id}")->assertOk();
        $this->getJson('/api/v1/reports/sales')->assertOk();

        $this->assertSame(0, OrderApproval::count());
    }

    public function test_approval_is_written_to_the_activity_log(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $this->post_($w['orderA']->id, $this->stampPayload($w['a']->id, $this->currentHash($w['orderA']->id, $w['a']->id)))->assertCreated();

        $log = ActivityLog::where('action', 'order_approved')->firstOrFail();
        $this->assertSame('Order', $log->entity_type);
        $this->assertSame($w['orderA']->id, (int) $log->entity_id);
        $this->assertSame($w['userA']->id, (int) $log->user_id);
        $this->assertStringContainsString($w['orderA']->order_number, $log->description);
    }

    public function test_a_failure_after_the_insert_leaves_no_row_no_log_and_no_orphan_file(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $hash = $this->currentHash($w['orderA']->id, $w['a']->id);

        $this->mock(ActivityLogger::class)->shouldReceive('log')->andThrow(new \RuntimeException('log gagal'));

        $this->post_($w['orderA']->id, ['method' => 'draw', 'image' => UploadedFile::fake()->image('s.png')] + $this->stampPayload($w['a']->id, $hash))
            ->assertStatus(500);

        $this->assertSame(0, OrderApproval::count());
        $this->assertSame(0, ActivityLog::where('action', 'order_approved')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
