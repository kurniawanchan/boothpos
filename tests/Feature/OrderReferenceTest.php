<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 054 (US8) — referensi transaksi: catatan teks dan/atau gambar pada satu penjualan POS.
 * Dikunci di sini: bentuk dan batas berkas, siapa boleh mengubah (owner/admin atau kasir penjualannya)
 * dan melihat (menu sales), berkas hanya lewat endpoint yang mengotorisasi (disk privat), jejak audit
 * dalam transaksi, dan referensi TIDAK pernah menyentuh uang, stok, atau status.
 */
class OrderReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $owner;

    private ProductVariant $variant;

    private CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'REF0001', 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => 100]);
        $this->session = CashierSession::factory()->create([
            'event_id' => Event::factory()->create(['status' => 'active'])->id, 'user_id' => $this->cashier->id, 'status' => 'open',
        ]);
    }

    private function as(User $user): static
    {
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    private function sale(): Order
    {
        $this->as($this->cashier);
        $id = $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 50000]],
        ])->assertCreated()->json('id');

        return Order::find($id);
    }

    private function png(string $name = 'wa.png', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40)->size($kb);
    }

    private function saveRef(Order $order, array $body)
    {
        // PUT multipart: Laravel hanya mengurai multipart pada POST, jadi dikirim sebagai POST + _method.
        return $this->post("/api/v1/orders/{$order->id}/reference", $body + ['_method' => 'PUT'], ['Accept' => 'application/json']);
    }

    // --- buat / ubah ---------------------------------------------------------------

    public function test_a_note_only_reference_is_saved(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['note' => 'Foto WA dari Budi, jam 10.15'])->assertOk()
            ->assertJsonPath('note', 'Foto WA dari Budi, jam 10.15')->assertJsonPath('has_image', false);

        $order->refresh();
        $this->assertSame('Foto WA dari Budi, jam 10.15', $order->reference_note);
        $this->assertNull($order->reference_image_path);
        $this->assertSame($this->cashier->id, $order->reference_updated_by);
    }

    public function test_an_image_only_reference_is_stored_privately_under_a_random_name(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['image' => $this->png('foto-pelanggan.png')])->assertOk()->assertJsonPath('has_image', true);

        $order->refresh();
        $this->assertStringStartsWith('order-references/', $order->reference_image_path);
        $this->assertStringNotContainsString('foto-pelanggan', $order->reference_image_path);
        Storage::disk('local')->assertExists($order->reference_image_path);
        $this->assertSame('image/png', $order->reference_image_mime);
    }

    public function test_replacing_the_image_deletes_the_old_file(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['image' => $this->png('a.png')])->assertOk();
        $old = $order->fresh()->reference_image_path;

        $this->saveRef($order, ['image' => $this->png('b.png')])->assertOk();

        $this->assertNotSame($old, $order->fresh()->reference_image_path);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($order->fresh()->reference_image_path);
    }

    public function test_the_note_can_change_while_the_image_stays_and_the_image_can_be_removed(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'awal', 'image' => $this->png()])->assertOk();
        $path = $order->fresh()->reference_image_path;

        $this->saveRef($order, ['note' => 'revisi'])->assertOk();
        $this->assertSame('revisi', $order->fresh()->reference_note);
        $this->assertSame($path, $order->fresh()->reference_image_path);

        $this->saveRef($order, ['remove_image' => true])->assertOk()->assertJsonPath('has_image', false);
        $this->assertNull($order->fresh()->reference_image_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_removes_note_and_image(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'x', 'image' => $this->png()])->assertOk();
        $path = $order->fresh()->reference_image_path;

        $this->deleteJson("/api/v1/orders/{$order->id}/reference")->assertOk();

        $order->refresh();
        $this->assertNull($order->reference_note);
        $this->assertNull($order->reference_image_path);
        Storage::disk('local')->assertMissing($path);
        $this->assertNotNull(ActivityLog::where('action', 'order_reference_removed')->first());
    }

    // --- validasi ------------------------------------------------------------------

    public function test_bad_files_and_oversized_notes_are_rejected_and_nothing_is_stored(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['image' => UploadedFile::fake()->create('dok.pdf', 20, 'application/pdf')])->assertStatus(422);
        $this->saveRef($order, ['image' => $this->png('big.png', 6000)])->assertStatus(422);
        $this->saveRef($order, ['note' => str_repeat('a', 1001)])->assertStatus(422);
        // Berkas yang hanya diganti ekstensinya (isi bukan gambar). UploadedFile::fake() melaporkan jenis dari
        // EKSTENSI, jadi untuk benar-benar menguji pembacaan isi dipakai berkas sungguhan di disk.
        $path = tempnam(sys_get_temp_dir(), 'ref');
        file_put_contents($path, 'ini bukan gambar sama sekali');
        $this->saveRef($order, ['image' => new UploadedFile($path, 'palsu.png', 'image/png', null, true)])->assertStatus(422);
        @unlink($path);

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
        $this->saveRef($order, ['note' => 'catatan', 'image' => $this->png()])->assertOk();
        $other = User::factory()->create(['role' => 'cashier']);

        $this->as($other);
        $this->saveRef($order, ['note' => 'ubahan orang lain'])->assertStatus(403);
        $this->deleteJson("/api/v1/orders/{$order->id}/reference")->assertStatus(403);
        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertOk()->assertJsonPath('note', 'catatan')->assertJsonPath('can_edit', false);
        $this->get("/api/v1/orders/{$order->id}/reference/image")->assertOk();
        $this->assertSame('catatan', $order->fresh()->reference_note);

        $this->as($this->owner);
        $this->saveRef($order, ['note' => 'ubah owner'])->assertOk();
        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertJsonPath('can_edit', true);
    }

    public function test_a_user_without_the_sales_menu_cannot_read_the_reference_or_image(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'rahasia', 'image' => $this->png()])->assertOk();
        $noSales = User::factory()->create(['role' => 'cashier']);
        $noSales->update(['role_id' => Role::factory()->create(['menu_keys' => ['dashboard']])->id]);
        $this->as($noSales->fresh());

        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertStatus(403);
        $this->get("/api/v1/orders/{$order->id}/reference/image")->assertStatus(403);
    }

    public function test_the_image_is_served_with_private_headers_and_unauthenticated_requests_are_refused(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['image' => $this->png()])->assertOk();

        $res = $this->get("/api/v1/orders/{$order->id}/reference/image")->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('image/png', $res->headers->get('Content-Type'));

        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/orders/{$order->id}/reference/image", ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_a_sale_without_a_reference_has_no_image(): void
    {
        $order = $this->sale();

        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertOk()->assertJsonPath('note', null)->assertJsonPath('has_image', false);
        $this->get("/api/v1/orders/{$order->id}/reference/image")->assertStatus(404);
    }

    // --- efek samping --------------------------------------------------------------

    public function test_the_reference_never_touches_money_stock_or_status_and_is_audited(): void
    {
        $order = $this->sale();
        $before = $order->only(['total_amount', 'paid_amount', 'change_amount', 'status']);
        $stock = $this->variant->fresh()->current_stock;

        $this->saveRef($order, ['note' => 'bukti', 'image' => $this->png()])->assertOk();

        $this->assertEquals($before, $order->fresh()->only(['total_amount', 'paid_amount', 'change_amount', 'status']));
        $this->assertSame($stock, $this->variant->fresh()->current_stock);
        $log = ActivityLog::where('action', 'order_reference_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->cashier->id, (int) $log->user_id);
    }

    public function test_a_voided_sale_can_still_be_annotated(): void
    {
        $order = $this->sale();
        $this->as($this->owner)->postJson("/api/v1/orders/{$order->id}/void", ['reason' => 'salah'])->assertOk();

        $this->saveRef($order, ['note' => 'alasan pembatalan: pelanggan tidak jadi'])->assertOk();
    }

    public function test_the_order_detail_carries_the_reference_block(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'ada', 'image' => $this->png()])->assertOk();

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('reference.note', 'ada')->assertJsonPath('reference.has_image', true)->assertJsonPath('reference.can_edit', true);
    }
}
