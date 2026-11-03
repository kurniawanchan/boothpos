<?php

namespace Tests\Feature;

use App\Models\PaymentProof;
use App\Models\SelfServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US1 — unggah bukti bayar publik: JPEG/PNG ≤ 5 MB, disk privat, nama acak, tanpa pengunggah (research D9).
 */
class SelfServiceProofUploadTest extends TestCase
{
    use RefreshDatabase;
    use SelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ssSetUp();
    }

    private function upload(UploadedFile $file, string $via = 'upload')
    {
        return $this->post($this->ssUrl('/proofs'), ['file' => $file, 'captured_via' => $via], ['Accept' => 'application/json']);
    }

    public function test_a_jpeg_upload_is_stored_privately_with_a_random_name_and_no_uploader(): void
    {
        $res = $this->upload(UploadedFile::fake()->image('bukti-asli.jpg', 600, 800), 'webcam')->assertCreated();

        $proof = PaymentProof::where('proof_token', $res->json('proof_token'))->firstOrFail();
        $this->assertNull($proof->uploaded_by);
        $this->assertNull($proof->payment_id);
        $this->assertSame('webcam', $proof->captured_via);
        $this->assertStringStartsWith('payment-proofs/', $proof->file_path);
        $this->assertStringNotContainsString('bukti-asli', $proof->file_path);
        Storage::disk('local')->assertExists($proof->file_path);
    }

    public function test_png_is_accepted_and_other_types_or_sizes_are_not(): void
    {
        $this->upload(UploadedFile::fake()->image('a.png'))->assertCreated();

        $this->upload(UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))->assertStatus(422);
        $this->upload(UploadedFile::fake()->create('skrip.php', 10, 'text/x-php'))->assertStatus(422);
        $this->upload(UploadedFile::fake()->image('besar.jpg')->size(6000))->assertStatus(422);
        $this->post($this->ssUrl('/proofs'), ['file' => UploadedFile::fake()->image('a.jpg'), 'captured_via' => 'telepati'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($this->ssUrl('/proofs'), ['captured_via' => 'upload'], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_file_renamed_to_jpg_but_not_an_image_is_refused_by_content(): void
    {
        // UploadedFile::fake() melaporkan MIME dari NAMA berkas, jadi dipakai berkas sungguhan agar MIME dibaca dari ISI-nya.
        $path = tempnam(sys_get_temp_dir(), 'ss');
        file_put_contents($path, '<?php echo 1;');
        $fake = new UploadedFile($path, 'palsu.jpg', 'image/jpeg', null, true);

        $this->upload($fake)->assertStatus(422);
        $this->assertSame(0, PaymentProof::count());
        @unlink($path);
    }

    public function test_uploads_are_rate_limited_per_ip(): void
    {
        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->upload(UploadedFile::fake()->image('a.jpg'))->getStatusCode();
        }

        $this->assertContains(429, $codes);
    }

    public function test_the_upload_needs_a_valid_qr_token(): void
    {
        $this->post('/api/v1/self-service/bukan-token/proofs', ['file' => UploadedFile::fake()->image('a.jpg'), 'captured_via' => 'upload'], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->assertSame(0, PaymentProof::count());
    }

    public function test_the_purge_command_removes_only_old_orphans(): void
    {
        $make = function (string $name, $createdAt) {
            Storage::disk('local')->put("payment-proofs/{$name}.jpg", 'x');

            return PaymentProof::create([
                'proof_token' => (string) \Illuminate\Support\Str::uuid(), 'file_path' => "payment-proofs/{$name}.jpg", 'original_name' => "{$name}.jpg",
                'mime_type' => 'image/jpeg', 'file_size' => 1, 'captured_via' => 'upload', 'uploaded_by' => null, 'created_at' => $createdAt,
            ]);
        };

        $oldOrphan = $make('old-orphan', now()->subDays(2));
        $recentOrphan = $make('recent-orphan', now()->subHours(2));
        $oldLinked = $make('old-linked', now()->subDays(2));
        $item = $this->makeMenuItem();
        $this->postJson($this->ssUrl('/orders'), $this->ssPayload([['variant_id' => $item->id, 'qty' => 1]], ['proof_token' => $oldLinked->proof_token]))->assertCreated();

        $this->artisan('self-service:purge-orphan-proofs')->assertSuccessful();

        $this->assertNull(PaymentProof::find($oldOrphan->id));
        Storage::disk('local')->assertMissing('payment-proofs/old-orphan.jpg');
        $this->assertNotNull(PaymentProof::find($recentOrphan->id));
        $this->assertNotNull(PaymentProof::find($oldLinked->id));
        Storage::disk('local')->assertExists('payment-proofs/old-linked.jpg');
        $this->assertSame(1, SelfServiceOrder::count());
    }
}
