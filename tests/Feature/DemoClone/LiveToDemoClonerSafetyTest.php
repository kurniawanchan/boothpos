<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\LiveFingerprint;
use App\Services\DemoClone\LiveToDemoCloner;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class LiveToDemoClonerSafetyTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private array $live;

    private array $oldDemo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->live = $this->buildGraph('live');
        $this->oldDemo = $this->buildGraph('demo');
    }

    /** Cloner yang menjalankan $hook di dalam transaksi, setelah penyalinan. */
    private function clonerWithHook(\Closure $hook): LiveToDemoCloner
    {
        return new class($hook) extends LiveToDemoCloner
        {
            public function __construct(private \Closure $hook) {}

            protected function afterClone(): void
            {
                ($this->hook)();
            }
        };
    }

    private function files(): array
    {
        return [Storage::disk('public')->allFiles(), Storage::disk('local')->allFiles()];
    }

    public function test_live_rows_and_files_are_identical_after_a_run(): void
    {
        $before = LiveFingerprint::capture();
        $liveBytes = Storage::disk('public')->get('products/live-product.jpg');

        (new LiveToDemoCloner)->run();

        // Hanya sidik jari yang boleh berubah di luar LIVE: users/settings/dll. tidak ditulis.
        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertSame($liveBytes, Storage::disk('public')->get('products/live-product.jpg'));
        Storage::disk('public')->assertExists('categories/live-category.jpg');
        Storage::disk('local')->assertExists('payment-proofs/live-proof.jpg');
    }

    public function test_a_change_to_live_during_the_run_aborts_and_rolls_everything_back(): void
    {
        $before = LiveFingerprint::capture();
        $cloner = $this->clonerWithHook(
            fn () => DB::table('customers')->where('id', $this->live['customer'])->update(['name' => 'Tampered']),
        );

        try {
            $cloner->run();
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertStringContainsString('LIVE berubah', $e->getMessage());
            $this->assertStringContainsString('customers', $e->getMessage());
        }

        $this->assertSame('Customer LIVE', DB::table('customers')->where('id', $this->live['customer'])->value('name'));
        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertTrue(DB::table('artists')->where('code', 'DMO')->exists(), 'DEMO lama harus kembali');
    }

    public function test_a_row_count_mismatch_aborts(): void
    {
        $cloner = $this->clonerWithHook(
            fn () => DB::table('artist_settlements')->where('data_mode', 'demo')->delete(),
        );

        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('artist_settlements');

        $cloner->run();
    }

    public function test_a_cross_mode_reference_aborts(): void
    {
        // Hanya baris DEMO baru yang diubah (menunjuk pelanggan LIVE). Sidik jari LIVE
        // tidak berubah, jadi HANYA cek integritas lintas mode yang bisa melempar.
        $liveCustomer = $this->live['customer'];
        $cloner = $this->clonerWithHook(function () use ($liveCustomer) {
            DB::table('orders')->where('data_mode', 'demo')->update(['customer_id' => $liveCustomer]);
        });
        $before = LiveFingerprint::capture();

        try {
            $cloner->run();
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertStringContainsString('orders.customer_id', $e->getMessage());
            $this->assertStringContainsString('lintas mode', $e->getMessage());
        }

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
    }

    public function test_a_live_row_pointing_at_a_demo_row_aborts_before_anything_is_wiped(): void
    {
        // orders.customer_id adalah ON DELETE SET NULL: tanpa cek awal, menghapus DEMO
        // akan diam-diam menulis NULL ke pesanan LIVE ini.
        $demoCustomer = $this->oldDemo['customer'];
        DB::table('orders')->where('id', $this->live['order'])->update(['customer_id' => $demoCustomer]);
        $before = LiveFingerprint::capture();

        try {
            (new LiveToDemoCloner)->run();
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertStringContainsString('orders.customer_id', $e->getMessage());
            $this->assertStringContainsString('tidak ada yang diubah', $e->getMessage());
        }

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertSame($demoCustomer, (int) DB::table('orders')->where('id', $this->live['order'])->value('customer_id'));
        $this->assertTrue(DB::table('customers')->where('id', $demoCustomer)->exists());
        $this->assertTrue(DB::table('artists')->where('code', 'DMO')->exists(), 'DEMO tidak boleh dihapus');
    }

    public function test_a_live_pos_draft_pointing_at_a_demo_event_aborts_before_anything_is_wiped(): void
    {
        // pos_drafts tidak disalin, tapi event_id-nya ON DELETE SET NULL dari events.
        $draft = $this->row('pos_drafts', [
            'data_mode' => 'live', 'user_id' => $this->live['user'], 'event_id' => $this->oldDemo['event'],
            'cart_snapshot' => '[]',
        ]);
        $before = LiveFingerprint::capture();

        try {
            (new LiveToDemoCloner)->run();
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertStringContainsString('pos_drafts.event_id', $e->getMessage());
        }

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertSame($this->oldDemo['event'], (int) DB::table('pos_drafts')->where('id', $draft)->value('event_id'));
        $this->assertTrue(DB::table('artists')->where('code', 'DMO')->exists(), 'DEMO tidak boleh dihapus');
    }

    public function test_failure_after_copy_restores_demo_and_removes_copied_files(): void
    {
        $filesBefore = $this->files();
        $demoArtistBefore = DB::table('artists')->where('data_mode', 'demo')->first();
        $fingerprintBefore = LiveFingerprint::capture();
        $liveProductBytes = Storage::disk('public')->get('products/live-product.jpg');
        $liveProofBytes = Storage::disk('local')->get('payment-proofs/live-proof.jpg');
        $cloner = $this->clonerWithHook(fn () => throw new \RuntimeException('boom'));

        try {
            $cloner->run();
            $this->fail('Seharusnya melempar');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertEquals($demoArtistBefore, DB::table('artists')->where('data_mode', 'demo')->first());
        $this->assertSame($filesBefore, $this->files(), 'salinan berkas harus dihapus saat gagal');
        Storage::disk('public')->assertExists('products/demo-product.jpg');
        // Spec §2.3: LIVE (baris DAN berkas) tidak boleh berubah sedikit pun oleh run yang gagal.
        $this->assertSame([], LiveFingerprint::diff($fingerprintBefore, LiveFingerprint::capture()));
        $this->assertSame($liveProductBytes, Storage::disk('public')->get('products/live-product.jpg'));
        $this->assertSame($liveProofBytes, Storage::disk('local')->get('payment-proofs/live-proof.jpg'));
    }

    public function test_a_failure_while_deleting_old_demo_files_after_commit_does_not_fail_the_run(): void
    {
        // Pembersihan berkas lama terjadi SESUDAH commit: kegagalannya tidak boleh
        // tampil sebagai "dibatalkan" karena DEMO sudah terlanjur diganti.
        $cloner = new class extends LiveToDemoCloner
        {
            protected function deleteUnreferencedFiles(array $stale): void
            {
                throw new \RuntimeException('disk penuh SENTINEL-PATH-123');
            }
        };

        $report = $cloner->run();

        $this->assertTrue($report['files_cleanup_failed']);
        $this->assertSame('ZLI', DB::table('artists')->where('data_mode', 'demo')->value('code'));
        $this->assertFalse(DB::table('artists')->where('code', 'DMO')->exists());
    }

    public function test_a_normal_run_reports_no_cleanup_failure(): void
    {
        $report = (new LiveToDemoCloner)->run();

        $this->assertFalse($report['files_cleanup_failed']);
    }

    public function test_a_failing_discard_does_not_hide_the_original_exception(): void
    {
        // Sesudah penyalinan, disk "rusak" lalu proses gagal: discardCopies() ikut
        // melempar, tetapi yang harus sampai ke pemanggil adalah galat ASLI.
        $cloner = $this->clonerWithHook(function () {
            Storage::shouldReceive('disk')->andThrow(new \LogicException('disk rusak'));

            throw new \RuntimeException('boom');
        });

        try {
            $cloner->run();
            $this->fail('Seharusnya melempar');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            $this->assertSame('boom', $e->getMessage());
        }
    }

    public function test_copied_images_and_proofs_are_independent_files(): void
    {
        (new LiveToDemoCloner)->run();

        $product = DB::table('products')->where('data_mode', 'demo')->first();
        $variant = DB::table('product_variants')->where('data_mode', 'demo')->whereNotNull('image_path')->first();
        $category = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('image_path')->first();
        $proof = DB::table('payment_proofs')->where('data_mode', 'demo')->first();

        $this->assertNotSame('products/live-product.jpg', $product->image_path);
        $this->assertSame('live-product-bytes', Storage::disk('public')->get($product->image_path));
        $this->assertSame('live-variant-bytes', Storage::disk('public')->get($variant->image_path));
        $this->assertSame('live-category-bytes', Storage::disk('public')->get($category->image_path));
        // bukti bayar tetap di disk PRIVAT
        $this->assertSame('live-proof-bytes', Storage::disk('local')->get($proof->file_path));
        $this->assertStringStartsWith('payment-proofs/', $proof->file_path);
        $this->assertNotSame('payment-proofs/live-proof.jpg', $proof->file_path);
        Storage::disk('public')->assertMissing($proof->file_path);
    }

    public function test_a_missing_live_image_becomes_null_and_a_missing_proof_row_is_skipped(): void
    {
        Storage::disk('public')->delete('products/live-product.jpg');
        Storage::disk('local')->delete('payment-proofs/live-proof.jpg');

        $report = (new LiveToDemoCloner)->run();

        $this->assertNull(DB::table('products')->where('data_mode', 'demo')->value('image_path'));
        $this->assertSame(0, DB::table('payment_proofs')->where('data_mode', 'demo')->count());
        $this->assertSame(['payment_proofs' => 1], $report['skipped']);
        $this->assertSame(2, $report['files']['missing']);
    }

    public function test_rows_sharing_one_live_file_share_one_demo_copy_but_never_with_live(): void
    {
        DB::table('product_variants')->where('id', $this->live['variant1'])->update(['image_path' => 'products/live-product.jpg']);

        (new LiveToDemoCloner)->run();

        $productPath = DB::table('products')->where('data_mode', 'demo')->value('image_path');
        $variantPath = DB::table('product_variants')->where('data_mode', 'demo')->whereNotNull('image_path')->value('image_path');
        $this->assertSame($productPath, $variantPath);
        $this->assertNotSame('products/live-product.jpg', $productPath);
    }

    public function test_wiping_demo_deletes_its_own_files_but_never_a_file_a_live_row_still_uses(): void
    {
        // Sisa kloning lama: baris DEMO menunjuk berkas milik LIVE.
        DB::table('products')->where('id', $this->oldDemo['product'])->update(['image_path' => 'products/live-product.jpg']);

        (new LiveToDemoCloner)->run();

        Storage::disk('public')->assertExists('products/live-product.jpg');
        // berkas DEMO lama yang tak dipakai siapa pun ikut dibersihkan
        Storage::disk('public')->assertMissing('products/demo-variant.jpg');
        Storage::disk('public')->assertMissing('categories/demo-category.jpg');
        Storage::disk('local')->assertMissing('payment-proofs/demo-proof.jpg');
    }

    public function test_result_is_identical_when_the_active_mode_is_demo(): void
    {
        $before = LiveFingerprint::capture();

        ModeGate::runAs(ModeGate::DEMO, fn () => (new LiveToDemoCloner)->run());

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertSame(1, DB::table('artists')->where('data_mode', 'live')->count());
        $this->assertSame('ZLI', DB::table('artists')->where('data_mode', 'demo')->value('code'));
    }

    public function test_running_twice_gives_the_same_result(): void
    {
        $first = (new LiveToDemoCloner)->run();
        $second = (new LiveToDemoCloner)->run();

        $this->assertSame($first['cloned'], $second['cloned']);
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'demo')->count());
        $this->assertSame(1, DB::table('artists')->where('data_mode', 'demo')->count());
        // dua run berturut-turut tidak menumpuk berkas: salinan run-1 dibersihkan di run-2
        $this->assertCount(6, Storage::disk('public')->allFiles()); // 3 LIVE + 3 salinan DEMO terkini
    }

    public function test_a_log_entry_records_the_clone_without_row_values(): void
    {
        (new LiveToDemoCloner)->run();

        $log = DB::table('activity_logs')->where('action', 'cloned_live_to_demo')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('example.test', (string) $log->new_values);
    }
}
