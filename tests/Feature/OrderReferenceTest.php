<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsReferenceSales;
use Tests\TestCase;

/**
 * 054 (US8) — referensi transaksi: catatan teks dan/atau gambar pada satu penjualan POS.
 * Dikunci di sini: bentuk dan batas berkas, siapa boleh mengubah (owner/admin atau kasir penjualannya)
 * dan melihat (menu sales), berkas hanya lewat endpoint yang mengotorisasi (disk privat), jejak audit
 * dalam transaksi, dan referensi TIDAK pernah menyentuh uang, stok, atau status.
 *
 * 055 — kontrak tulis berubah dari satu `image`/`remove_image` menjadi `images[]`/`remove_image_ids[]`
 * dan gambar dilayani per id (`/reference/images/{image}`); aturan 054 di atas tetap sama. Aturan khusus
 * banyak-gambar (batas, urutan, rollback, binding bertingkat) ada di OrderReferenceImagesTest.
 */
class OrderReferenceTest extends TestCase
{
    use BuildsReferenceSales;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpReferenceSales();
    }

    // --- buat / ubah ---------------------------------------------------------------

    public function test_a_note_only_reference_is_saved(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['note' => 'Foto WA dari Budi, jam 10.15'])->assertOk()
            ->assertJsonPath('note', 'Foto WA dari Budi, jam 10.15')->assertJsonPath('has_image', false)
            ->assertJsonPath('images', [])->assertJsonPath('image_count', 0);

        $order->refresh();
        $this->assertSame('Foto WA dari Budi, jam 10.15', $order->reference_note);
        $this->assertSame(0, $order->referenceImages()->count());
        $this->assertSame($this->cashier->id, $order->reference_updated_by);
    }

    public function test_an_image_only_reference_is_stored_privately_under_a_random_name(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['images' => [$this->png('foto-pelanggan.png')]])->assertOk()
            ->assertJsonPath('has_image', true)->assertJsonPath('image_count', 1);

        $image = $order->referenceImages()->sole();
        $this->assertStringStartsWith('order-references/', $image->path);
        $this->assertStringNotContainsString('foto-pelanggan', $image->path);
        Storage::disk('local')->assertExists($image->path);
        $this->assertSame('image/png', $image->mime);
        $this->assertSame($this->cashier->id, $image->uploaded_by);
    }

    public function test_the_note_can_change_while_images_stay_and_an_image_can_be_removed(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'awal', 'images' => [$this->png()]])->assertOk();
        $image = $order->referenceImages()->sole();

        $this->saveRef($order, ['note' => 'revisi'])->assertOk();
        $this->assertSame('revisi', $order->fresh()->reference_note);
        $this->assertSame($image->id, $order->referenceImages()->sole()->id);

        $this->saveRef($order, ['remove_image_ids' => [$image->id]])->assertOk()->assertJsonPath('has_image', false);
        $this->assertSame(0, $order->referenceImages()->count());
        Storage::disk('local')->assertMissing($image->path);
    }

    public function test_delete_removes_note_and_every_image(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'x', 'images' => $this->pngs(2)])->assertOk();
        $paths = $order->referenceImages()->pluck('path')->all();

        $this->deleteJson("/api/v1/orders/{$order->id}/reference")->assertOk()
            ->assertJsonPath('images', [])->assertJsonPath('image_count', 0);

        $order->refresh();
        $this->assertNull($order->reference_note);
        $this->assertSame(0, $order->referenceImages()->count());
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $log = ActivityLog::where('action', 'order_reference_removed')->sole();
        $this->assertSame(2, $log->old_values['image_count']);
    }

    // --- validasi ------------------------------------------------------------------

    public function test_bad_files_and_oversized_notes_are_rejected_and_nothing_is_stored(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['images' => [UploadedFile::fake()->create('dok.pdf', 20, 'application/pdf')]])->assertStatus(422);
        $this->saveRef($order, ['images' => [$this->png('big.png', 6000)]])->assertStatus(422);
        $this->saveRef($order, ['note' => str_repeat('a', 1001)])->assertStatus(422);
        $this->saveRef($order, ['images' => [$this->fakePngWithTextContent()]])->assertStatus(422)->assertJsonValidationErrors('images.0');

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertFalse($order->fresh()->hasReference());
    }

    public function test_a_change_that_would_leave_the_reference_empty_is_refused(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'satu-satunya'])->assertOk();

        $this->saveRef($order, ['note' => ''])->assertStatus(422);
        $this->saveRef($order, [])->assertStatus(422);
        $this->assertSame('satu-satunya', $order->fresh()->reference_note);
    }

    // --- izin ----------------------------------------------------------------------

    public function test_only_owner_admin_or_the_sales_cashier_may_edit_but_every_sales_user_may_read(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'catatan', 'images' => [$this->png()]])->assertOk();
        $imageId = $order->referenceImages()->sole()->id;
        $other = User::factory()->create(['role' => 'cashier']);

        $this->as($other);
        $this->saveRef($order, ['note' => 'ubahan orang lain'])->assertStatus(403);
        $this->saveRef($order, ['images' => [$this->png()]])->assertStatus(403);
        $this->saveRef($order, ['remove_image_ids' => [$imageId]])->assertStatus(403);
        $this->deleteJson("/api/v1/orders/{$order->id}/reference")->assertStatus(403);
        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertOk()->assertJsonPath('note', 'catatan')->assertJsonPath('can_edit', false);
        $this->get($this->imageUrl($order, $imageId))->assertOk();
        $this->assertSame('catatan', $order->fresh()->reference_note);
        $this->assertSame(1, $order->referenceImages()->count());

        $this->as($this->owner);
        $this->saveRef($order, ['note' => 'ubah owner'])->assertOk();
        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertJsonPath('can_edit', true);
    }

    public function test_a_user_without_the_sales_menu_cannot_read_the_reference_or_image(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'rahasia', 'images' => [$this->png()]])->assertOk();
        $imageId = $order->referenceImages()->sole()->id;
        $noSales = User::factory()->create(['role' => 'cashier']);
        $noSales->update(['role_id' => Role::factory()->create(['menu_keys' => ['dashboard']])->id]);
        $this->as($noSales->fresh());

        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertStatus(403);
        $this->get($this->imageUrl($order, $imageId), ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_the_image_is_served_with_private_headers_and_unauthenticated_requests_are_refused(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => [$this->png()]])->assertOk();
        $imageId = $order->referenceImages()->sole()->id;

        $res = $this->get($this->imageUrl($order, $imageId))->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('image/png', $res->headers->get('Content-Type'));

        $this->app['auth']->forgetGuards();
        $this->get($this->imageUrl($order, $imageId), ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_a_sale_without_a_reference_has_no_image(): void
    {
        $order = $this->sale();

        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertOk()
            ->assertJsonPath('note', null)->assertJsonPath('has_image', false)->assertJsonPath('images', []);
        $this->get($this->imageUrl($order, 999999), ['Accept' => 'application/json'])->assertStatus(404);
    }

    // --- efek samping --------------------------------------------------------------

    public function test_the_reference_never_touches_money_stock_or_status_and_is_audited(): void
    {
        $order = $this->sale();
        $before = $order->only(['total_amount', 'paid_amount', 'change_amount', 'status']);
        $stock = $this->variant->fresh()->current_stock;
        $payments = $order->payments()->get(['id', 'amount', 'method', 'verification'])->toArray();

        $this->saveRef($order, ['note' => 'bukti', 'images' => $this->pngs(2)])->assertOk();

        $this->assertEquals($before, $order->fresh()->only(['total_amount', 'paid_amount', 'change_amount', 'status']));
        $this->assertSame($stock, $this->variant->fresh()->current_stock);
        $this->assertEquals($payments, $order->payments()->get(['id', 'amount', 'method', 'verification'])->toArray());
        $log = ActivityLog::where('action', 'order_reference_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->cashier->id, (int) $log->user_id);
    }

    public function test_a_voided_sale_can_still_be_annotated(): void
    {
        $order = $this->sale();
        $this->as($this->owner)->postJson("/api/v1/orders/{$order->id}/void", ['reason' => 'salah'])->assertOk();

        $this->saveRef($order, ['note' => 'alasan pembatalan: pelanggan tidak jadi', 'images' => [$this->png()]])->assertOk();
    }

    public function test_the_order_detail_carries_the_reference_block(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'ada', 'images' => [$this->png()]])->assertOk();

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('reference.note', 'ada')->assertJsonPath('reference.has_image', true)
            ->assertJsonPath('reference.image_count', 1)->assertJsonPath('reference.can_edit', true);
    }
}
