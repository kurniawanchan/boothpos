<?php

namespace Tests\Feature\OrderApproval;

use App\Models\OrderApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApproveValidationTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function send(array $w, array $extra, ?int $orderId = null, ?int $artistId = null)
    {
        $orderId ??= $w['orderA']->id;
        $artistId ??= $w['a']->id;
        $payload = $this->stampPayload($artistId, $this->currentHash($orderId, $artistId));

        return $this->post("/api/v1/orders/{$orderId}/approvals", $extra + $payload, ['Accept' => 'application/json']);
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'apr');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_draw_and_upload_require_an_image_and_stamp_forbids_one(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $this->send($w, ['method' => 'draw'])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->send($w, ['method' => 'upload'])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->send($w, ['method' => 'stamp', 'image' => UploadedFile::fake()->image('a.png')])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->send($w, ['method' => 'bogus'])->assertStatus(422)->assertJsonValidationErrors('method');

        $this->assertSame(0, OrderApproval::count());
    }

    public function test_file_type_is_read_from_content_not_the_name(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        // Teks bernama .png, dan PDF bernama .jpg: ditolak.
        $this->send($w, ['method' => 'upload', 'image' => $this->realFile('ttd.png', 'bukan gambar sama sekali')])->assertStatus(422);
        $this->send($w, ['method' => 'upload', 'image' => $this->realFile('ttd.jpg', "%PDF-1.4\n%fake")])->assertStatus(422);
        $this->send($w, ['method' => 'upload', 'image' => UploadedFile::fake()->create('ttd.gif', 10, 'image/gif')])->assertStatus(422);
        $this->send($w, ['method' => 'upload', 'image' => $this->realFile('kosong.png', '')])->assertStatus(422);

        $this->assertSame(0, OrderApproval::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_files_over_five_megabytes_are_refused(): void
    {
        Storage::fake('local');
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');

        $this->send($w, ['method' => 'upload', 'image' => UploadedFile::fake()->create('besar.png', 5121, 'image/png')])->assertStatus(422);
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_a_seller_without_items_in_the_sale_is_refused(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');

        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", $this->stampPayload($w['b']->id, 'v1:apapun'), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('artist_id');
        $this->post("/api/v1/orders/{$w['orderA']->id}/approvals", $this->stampPayload(999999, 'v1:apapun'), ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(0, OrderApproval::count());
    }

    public function test_a_voided_sale_cannot_be_approved_and_keeps_existing_rows(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['owner'], 'sanctum');
        $hash = $this->currentHash($w['orderAB']->id, $w['a']->id);
        $this->send($w, ['method' => 'stamp'], $w['orderAB']->id, $w['a']->id)->assertCreated();

        $w['orderAB']->update(['status' => 'voided']);

        $this->send($w, ['method' => 'stamp'], $w['orderAB']->id, $w['b']->id)->assertStatus(409)->assertJsonPath('code', 'order_voided');
        $this->assertSame(1, OrderApproval::count());
        $this->getJson("/api/v1/orders/{$w['orderAB']->id}/approvals")->assertJsonPath('data.status', 'voided');
        $this->assertNotEmpty($hash);
    }
}
