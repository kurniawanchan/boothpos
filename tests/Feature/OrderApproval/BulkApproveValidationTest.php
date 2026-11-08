<?php

namespace Tests\Feature\OrderApproval;

use App\Models\ActivityLog;
use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 064 (US2, FR-007) — masukan salah ditolak SEBELUM penjualan mana pun diproses: nol baris, nol berkas, nol log. */
class BulkApproveValidationTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    /** Berkas NYATA: UploadedFile::fake() melaporkan MIME dari ekstensi, jadi tak bisa menguji pembacaan isi berkas. */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function assertNothingRecorded(): void
    {
        $this->assertSame(0, OrderApproval::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, ActivityLog::whereIn('action', ['order_approved', 'order_bulk_approved'])->count());
    }

    public function test_bad_images_and_methods_are_refused_with_nothing_written(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $ids = array_map(fn ($o) => $o->id, $this->makeSalesOfA($w, 2));
        $this->actingAs($w['userA'], 'sanctum');
        $plan = $this->bulkPreview($ids)->json('data.plan');

        $cases = [
            'draw tanpa gambar' => ['draw', null, 'image'],
            'upload tanpa gambar' => ['upload', null, 'image'],
            'stempel dengan gambar' => ['stamp', UploadedFile::fake()->image('x.png'), 'image'],
            'berkas kosong' => ['draw', UploadedFile::fake()->create('kosong.png', 0, 'image/png'), 'image'],
            'terlalu besar' => ['draw', UploadedFile::fake()->create('besar.png', 6000, 'image/png'), 'image'],
            'pdf bernama png' => ['upload', $this->realFile('palsu.png', "%PDF-1.4\n%fake pdf content"), 'image'],
            'gif' => ['upload', UploadedFile::fake()->image('anim.gif'), 'image'],
            'metode ngawur' => ['scribble', null, 'method'],
        ];

        foreach ($cases as $label => [$method, $image, $errorKey]) {
            $this->bulkApply($ids, $method, $plan, $image)->assertStatus(422, "kasus: {$label}")->assertJsonValidationErrors([$errorKey]);
            $this->assertNothingRecorded();
        }
    }

    public function test_corrupt_json_fields_are_a_422_not_a_500(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $this->post('/api/v1/orders/approvals/bulk', ['order_ids' => '[1,2', 'plan' => '[]', 'method' => 'stamp'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['order_ids']);
        $this->post('/api/v1/orders/approvals/bulk', ['order_ids' => json_encode([$w['orderA']->id]), 'plan' => '{oops', 'method' => 'stamp'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['plan']);
        $this->post('/api/v1/orders/approvals/bulk', ['order_ids' => json_encode([$w['orderA']->id, $w['orderA']->id]), 'plan' => '[]', 'method' => 'stamp'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['order_ids.1']);
        $this->assertNothingRecorded();
    }
}
