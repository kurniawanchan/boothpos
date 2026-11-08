<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 064 (US2) — satu gambar per batch: disimpan SEKALI, dibagi semua baris, hak lihat tetap per baris. */
class BulkApproveImageTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    public function test_draw_and_upload_share_exactly_one_stored_file_across_the_batch(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        foreach (['draw' => UploadedFile::fake()->image('signature.png'), 'upload' => UploadedFile::fake()->image('ttd.jpg')] as $method => $image) {
            Storage::disk('local')->deleteDirectory('order-approvals');
            OrderApproval::query()->delete();
            $ids = array_map(fn ($o) => $o->id, $this->makeSalesOfA($w, 3));

            $this->bulkApply($ids, $method, null, $image)->assertOk()->assertJsonPath('data.approved_orders', 3);

            $rows = OrderApproval::all();
            $this->assertCount(3, $rows);
            $this->assertSame([$method], $rows->pluck('method')->unique()->values()->all());
            $this->assertCount(1, $rows->pluck('image_path')->unique(), 'satu path dibagi seluruh batch');
            $this->assertCount(1, Storage::disk('local')->allFiles('order-approvals'), 'hanya SATU berkas ditulis');
            $this->assertNotNull($rows->first()->image_mime);
        }
    }

    public function test_viewing_the_shared_image_is_still_decided_per_approval(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $ids = array_map(fn ($o) => $o->id, $this->makeSalesOfA($w, 2));
        $this->actingAs($w['userA'], 'sanctum');
        $this->bulkApply($ids, 'draw', null, UploadedFile::fake()->image('s.png'))->assertOk();
        $approval = OrderApproval::where('order_id', $ids[1])->firstOrFail();

        $this->getJson("/api/v1/orders/{$ids[1]}/approvals/{$approval->id}/image")->assertOk();
        $this->actingAs($w['owner'], 'sanctum');
        $this->getJson("/api/v1/orders/{$ids[1]}/approvals/{$approval->id}/image")->assertOk();
        $this->actingAs($w['userB'], 'sanctum');
        $this->getJson("/api/v1/orders/{$ids[1]}/approvals/{$approval->id}/image")->assertStatus(404); // sale tak terlihat seller B
        $this->actingAs($w['cashier'], 'sanctum');
        $this->getJson("/api/v1/orders/{$ids[1]}/approvals/{$approval->id}/image")->assertStatus(403)->assertJsonPath('code', 'image_forbidden');
    }

    public function test_a_batch_where_nothing_is_approved_leaves_no_file_behind(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['cashier'], 'sanctum'); // tanpa seller → semua dilewati

        $data = $this->bulkApply([$w['orderA']->id], 'draw', null, UploadedFile::fake()->image('s.png'))->assertOk()->json('data');

        $this->assertSame(0, $data['approved_sellers']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_per_sale_failure_keeps_the_file_because_other_rows_use_it(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        [$ok, $boom] = $this->makeSalesOfA($w, 2);
        $this->actingAs($w['userA'], 'sanctum');
        OrderApproval::creating(function ($m) use ($boom) {
            if ($m->order_id === $boom->id) {
                throw new \RuntimeException('simulasi gagal');
            }
        });

        $data = $this->bulkApply([$ok->id, $boom->id], 'draw', null, UploadedFile::fake()->image('s.png'))->assertOk()->json('data');

        $this->assertSame(1, $data['approved_orders']);
        $this->assertSame(['failed' => 1], (array) $data['skipped_by_reason']);
        $this->assertCount(1, Storage::disk('local')->allFiles('order-approvals'));
        $this->assertSame(OrderApproval::first()->image_path, Storage::disk('local')->allFiles('order-approvals')[0]);
    }
}
