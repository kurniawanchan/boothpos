<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsReferenceSales;
use Tests\TestCase;

/**
 * 055 — referensi transaksi dengan BANYAK gambar (maks. 10 per penjualan).
 *
 * Dikunci di sini: simpan bersifat DELTA (`images[]` ditambahkan, `remove_image_ids[]` dihapus) dalam satu
 * transaksi berkunci, jadi dua pengguna yang menyimpan dari data lama tidak saling menghapus gambar; batas
 * dihitung terhadap gambar yang BENAR-BENAR tersimpan saat menyimpan (409); satu berkas buruk menggagalkan
 * seluruh simpanan tanpa meninggalkan berkas; gambar hanya dilayani dalam konteks penjualannya sendiri
 * (scoped binding) dan mode DEMO/LIVE-nya.
 */
class OrderReferenceImagesTest extends TestCase
{
    use BuildsReferenceSales;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpReferenceSales();
    }

    /** @return list<int> posisi gambar referensi penjualan, urut tampil */
    private function positions(Order $order): array
    {
        return $order->referenceImages()->pluck('position')->map(fn ($p) => (int) $p)->all();
    }

    // --- tambah banyak sekaligus -----------------------------------------------------

    public function test_several_images_in_one_save_are_stored_in_order_with_one_audit_row(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['images' => $this->pngs(3)])->assertOk()
            ->assertJsonPath('image_count', 3)->assertJsonPath('max_images', 10)
            ->assertJsonCount(3, 'images');

        $this->assertSame([1, 2, 3], $this->positions($order));
        $log = ActivityLog::where('action', 'order_reference_updated')->sole();
        $this->assertSame(3, $log->new_values['images_added']);
        $this->assertSame(0, $log->new_values['images_removed']);
        $this->assertSame(3, $log->new_values['image_count']);
    }

    public function test_adding_more_later_appends_and_keeps_existing_images(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => $this->pngs(3)])->assertOk();
        $before = $order->referenceImages()->get(['id', 'path', 'position'])->toArray();

        $this->saveRef($order, ['images' => $this->pngs(2)])->assertOk()->assertJsonPath('image_count', 5);

        $this->assertSame([1, 2, 3, 4, 5], $this->positions($order));
        $this->assertEquals($before, $order->referenceImages()->limit(3)->get(['id', 'path', 'position'])->toArray());
    }

    public function test_the_response_lists_images_in_display_order(): void
    {
        $order = $this->sale();
        $res = $this->saveRef($order, ['images' => $this->pngs(2)])->assertOk();

        $ids = $order->referenceImages()->pluck('id')->all();
        $this->assertSame($ids, array_column($res->json('images'), 'id'));
        $this->assertSame([1, 2], array_column($res->json('images'), 'position'));
        $this->assertSame('image/png', $res->json('images.0.mime'));
        $this->assertIsInt($res->json('images.0.size_bytes'));
    }

    // --- batas ---------------------------------------------------------------------

    public function test_going_over_the_stored_limit_is_a_conflict_and_writes_nothing(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => $this->pngs(9)])->assertOk();
        $files = Storage::disk('local')->allFiles();

        $this->saveRef($order, ['images' => $this->pngs(2)])->assertStatus(409)
            ->assertJsonPath('code', 'reference_image_limit')->assertJsonPath('max', 10)->assertJsonPath('remaining', 1);

        $this->assertSame(9, $order->referenceImages()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_removals_in_the_same_save_free_slots_for_new_images(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => $this->pngs(10)])->assertOk();
        $first = $order->referenceImages()->first();

        $this->saveRef($order, ['images' => [$this->png()], 'remove_image_ids' => [$first->id]])->assertOk()
            ->assertJsonPath('image_count', 10);
    }

    public function test_more_than_ten_files_in_one_request_is_a_validation_error(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['images' => $this->pngs(11)])->assertStatus(422)->assertJsonValidationErrors('images');
        $this->assertSame(0, $order->referenceImages()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_saves_built_from_the_same_stale_view_never_drop_each_others_images(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => $this->pngs(1)])->assertOk();

        // Dua pengguna membuka dialog bersamaan; masing-masing hanya mengirim yang IA tambahkan (delta).
        $this->as($this->cashier)->saveRef($order, ['images' => [$this->png('a.png')]])->assertOk();
        $this->as($this->owner)->saveRef($order, ['images' => [$this->png('b.png')]])->assertOk();

        $this->assertSame([1, 2, 3], $this->positions($order));
    }

    public function test_the_limit_counts_what_another_user_already_stored(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => $this->pngs(9)])->assertOk();

        $this->as($this->cashier)->saveRef($order, ['images' => [$this->png()]])->assertOk();
        $this->as($this->owner)->saveRef($order, ['images' => [$this->png()]])->assertStatus(409)
            ->assertJsonPath('remaining', 0);
        $this->assertSame(10, $order->referenceImages()->count());
    }

    // --- berkas buruk / rollback ---------------------------------------------------

    public function test_one_bad_file_fails_the_whole_save_and_leaves_no_file(): void
    {
        $order = $this->sale();

        $this->saveRef($order, ['images' => [$this->png('ok-1.png'), $this->fakePngWithTextContent(), $this->png('ok-2.png')]])
            ->assertStatus(422)->assertJsonValidationErrors('images.1');

        $this->assertSame(0, $order->referenceImages()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_failure_after_files_were_written_rolls_back_rows_and_files(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'awal', 'images' => [$this->png()]])->assertOk();
        $kept = $order->referenceImages()->sole();

        $this->mock(ActivityLogger::class, fn ($m) => $m->shouldReceive('log')->andThrow(new \RuntimeException('log gagal')));
        $this->withoutExceptionHandling();

        try {
            $this->saveRef($order, ['note' => 'baru', 'images' => $this->pngs(2), 'remove_image_ids' => [$kept->id]]);
            $this->fail('Simpanan seharusnya gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('log gagal', $e->getMessage());
        }

        $this->assertSame('awal', $order->fresh()->reference_note);
        $this->assertSame([$kept->id], $order->referenceImages()->pluck('id')->all());
        $this->assertSame([$kept->path], Storage::disk('local')->allFiles());
    }

    // --- hapus satu ----------------------------------------------------------------

    public function test_removing_one_image_keeps_the_others_in_order_with_gaps(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'tiga', 'images' => $this->pngs(3)])->assertOk();
        [$one, $two, $three] = $order->referenceImages()->get()->all();

        $this->saveRef($order, ['remove_image_ids' => [$two->id]])->assertOk()->assertJsonPath('image_count', 2);

        $this->assertSame([$one->id, $three->id], $order->referenceImages()->pluck('id')->all());
        $this->assertSame([1, 3], $this->positions($order));
        $this->assertSame('tiga', $order->fresh()->reference_note);
        Storage::disk('local')->assertMissing($two->path);
        Storage::disk('local')->assertExists($one->path);
        Storage::disk('local')->assertExists($three->path);
        $this->get($this->imageUrl($order, $two->id), ['Accept' => 'application/json'])->assertStatus(404);
        $this->assertSame(1, ActivityLog::where('action', 'order_reference_updated')->latest('id')->first()->new_values['images_removed']);
    }

    public function test_ids_of_another_sales_images_or_already_removed_ids_are_ignored(): void
    {
        $mine = $this->sale();
        $theirs = $this->sale();
        $this->saveRef($theirs, ['images' => [$this->png()]])->assertOk();
        $foreign = $theirs->referenceImages()->sole();
        $this->saveRef($mine, ['note' => 'punyaku'])->assertOk();

        $this->saveRef($mine, ['images' => [$this->png()], 'remove_image_ids' => [$foreign->id, 999999]])->assertOk()
            ->assertJsonPath('image_count', 1);

        $this->assertSame(1, $theirs->referenceImages()->count());
        Storage::disk('local')->assertExists($foreign->path);
    }

    public function test_removing_the_only_image_of_a_note_less_reference_is_refused(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => [$this->png()]])->assertOk();
        $image = $order->referenceImages()->sole();

        $this->saveRef($order, ['remove_image_ids' => [$image->id]])->assertStatus(422);

        $this->assertSame(1, $order->referenceImages()->count());
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_row(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'sama'])->assertOk();

        $this->saveRef($order, ['note' => 'sama', 'remove_image_ids' => [999999]])->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'order_reference_updated')->count());
    }

    // --- melayani gambar -----------------------------------------------------------

    public function test_an_image_is_only_served_through_its_own_sale(): void
    {
        $a = $this->sale();
        $b = $this->sale();
        $this->saveRef($b, ['images' => [$this->png()]])->assertOk();
        $imageOfB = $b->referenceImages()->sole();

        $this->get($this->imageUrl($a, $imageOfB->id), ['Accept' => 'application/json'])->assertStatus(404);
        $this->get($this->imageUrl($b, $imageOfB->id))->assertOk();
    }

    public function test_an_image_of_a_sale_in_the_other_data_mode_is_not_found(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => [$this->png()]])->assertOk();
        $image = $order->referenceImages()->sole();
        DB::table('orders')->where('id', $order->id)->update(['data_mode' => 'demo']);

        $this->get($this->imageUrl($order, $image->id), ['Accept' => 'application/json'])->assertStatus(404);
        $this->getJson("/api/v1/orders/{$order->id}/reference")->assertStatus(404);
    }

    public function test_a_missing_file_answers_404_and_the_old_single_image_route_is_gone(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['images' => $this->pngs(2)])->assertOk();
        [$gone, $ok] = $order->referenceImages()->get()->all();
        Storage::disk('local')->delete($gone->path);

        $this->get($this->imageUrl($order, $gone->id), ['Accept' => 'application/json'])->assertStatus(404);
        $this->get($this->imageUrl($order, $ok->id))->assertOk();
        $this->get("/api/v1/orders/{$order->id}/reference/image", ['Accept' => 'application/json'])->assertStatus(404);
    }

    public function test_the_order_detail_and_the_reference_endpoint_return_the_same_block(): void
    {
        $order = $this->sale();
        $this->saveRef($order, ['note' => 'blok', 'images' => $this->pngs(2)])->assertOk();

        $fromDetail = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('reference');
        $fromEndpoint = $this->getJson("/api/v1/orders/{$order->id}/reference")->assertOk()->json();

        $this->assertSame($fromEndpoint, $fromDetail);
        $this->assertEqualsCanonicalizing(
            ['note', 'images', 'image_count', 'has_image', 'max_images', 'updated_at', 'updated_by_name', 'can_edit'],
            array_keys($fromEndpoint),
        );
    }

    // --- daftar Sales --------------------------------------------------------------

    public function test_the_sales_list_flags_image_only_references_without_a_query_per_row(): void
    {
        $orders = [$this->sale(), $this->sale(), $this->sale()];
        $this->saveRef($orders[0], ['images' => [$this->png()]])->assertOk();
        $this->saveRef($orders[1], ['note' => 'catatan saja'])->assertOk();

        $this->as($this->owner);
        $this->getJson('/api/v1/reports/sales')->assertOk(); // pemanasan cache pengaturan/lisensi (048)

        DB::enableQueryLog();
        $rows = collect($this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions'))->keyBy('id');
        $imageQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'order_reference_images'))->count();
        DB::disableQueryLog();

        $this->assertTrue($rows[$orders[0]->id]['has_reference']);
        $this->assertTrue($rows[$orders[1]->id]['has_reference']);
        $this->assertFalse($rows[$orders[2]->id]['has_reference']);
        $this->assertLessThanOrEqual(1, $imageQueries, 'has_reference harus memakai satu agregat, bukan kueri per baris');
    }
}
