<?php

namespace Tests\Feature;

use App\Console\Commands\SyncStockFromTransactions;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Backup\DatabaseDumper;
use App\Services\StockService;
use App\Services\StockSync\StockSyncApplier;
use App\Services\StockSync\StockSyncPlanner;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;

/**
 * 051-stock-sync-from-transactions — koreksi stok LIVE yang diketik SESUDAH transaksi sudah ada.
 *
 * Semua skenario dibangun lewat jalur aplikasi yang asli (POST /orders, void, alur status pre-order, StockService),
 * BUKAN dengan menyisipkan baris pergerakan manual: aturan fitur ini bergantung pada bentuk buku besar yang
 * benar-benar ditulis aplikasi (jenis pergerakan, urutan id, penanda referensi).
 */
class StockTransactionSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CashierSession $session;

    private string $backupDir;

    private FakeDatabaseDumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir().'/boothpos-stocksync-test-'.uniqid();
        config(['backup.path' => $this->backupDir, 'backup.external_path' => null]);
        Storage::fake('local');
        Storage::fake('public');
        $this->dumper = new FakeDatabaseDumper;
        $this->app->instance(DatabaseDumper::class, $this->dumper);

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');

        $event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create([
            'event_id' => $event->id, 'user_id' => $this->owner->id, 'status' => 'open',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        parent::tearDown();
    }

    // --- pembantu: semuanya lewat jalur aplikasi ---------------------------------------------

    private function variant(string $sku, int $stock, string $name = 'Varian', array $productAttrs = []): ProductVariant
    {
        $product = Product::factory()->create(array_merge([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ], $productAttrs));

        return $product->variants()->create([
            'sku' => $sku, 'variant_name' => $name, 'sell_price' => 25000, 'cost_price' => 10000, 'current_stock' => $stock, 'is_active' => true,
        ]);
    }

    /** Penjualan POS lewat API; kembalikan id order. */
    private function sell(ProductVariant $v, int $qty): int
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $v->id, 'qty' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => 25000 * $qty]],
        ])->assertCreated()->json('id');
    }

    private function voidOrder(int $orderId): void
    {
        app(\App\Services\OrderService::class)->void(\App\Models\Order::findOrFail($orderId), 'salah input', $this->owner);
    }

    private function createPreorder(ProductVariant $v, int $qty, ?Customer $customer = null): int
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $v->id, 'qty' => $qty]],
        ])->assertCreated()->json('id');
    }

    /** Pre-order sampai DISERAHKAN (kedatangan +qty lalu serah-terima −qty). */
    private function handOver(ProductVariant $v, int $qty, ?Customer $customer = null): int
    {
        $id = $this->createPreorder($v, $qty, $customer);
        $this->patchJson("/api/v1/preorders/{$id}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$id}/status", ['status' => 'arrived'])->assertOk();
        $this->postJson("/api/v1/preorders/{$id}/payments", ['method' => 'cash', 'amount' => 25000 * $qty, 'purpose' => 'settlement'])->assertCreated();
        $this->patchJson("/api/v1/preorders/{$id}/status", ['status' => 'handed_over'])->assertOk();

        return $id;
    }

    /** "Mengetik stok riil": jalur yang sama dengan impor stok Excel (adjustment absolut → selisih). */
    private function typeStock(ProductVariant $v, int $target): StockMovement
    {
        $delta = $target - $v->fresh()->current_stock;

        return app(StockService::class)->applyMovement(
            $v, 'adjustment', $delta, 'MasterDataImport', null, 'Impor massal master data', $this->owner->id
        );
    }

    private function plan(): array
    {
        return app(StockSyncPlanner::class)->plan();
    }

    private function row(array $plan, ProductVariant $v): array
    {
        $row = collect($plan['rows'])->firstWhere('variant_id', $v->id);
        $this->assertNotNull($row, "varian {$v->sku} tidak ada di rencana");

        return $row;
    }

    private function movementCount(): int
    {
        return StockMovement::withoutGlobalScopes()->count();
    }

    // --- US1: dry-run -------------------------------------------------------------------------

    public function test_stock_typed_after_sales_is_reduced_by_those_sales(): void
    {
        $v = $this->variant('SYNC0001', 100);
        $this->sell($v, 8);
        $this->typeStock($v, 50);

        $row = $this->row($this->plan(), $v);

        $this->assertSame(50, $row['current_stock']);
        $this->assertSame(8, $row['taken']);
        $this->assertSame(42, $row['new_stock']);
        $this->assertSame(-8, $row['delta']);
        $this->assertSame(0, $row['shortfall']);
        $this->assertSame('akan_diubah', $row['status']);
    }

    public function test_voids_reduce_the_amount_and_only_handed_over_preorders_count(): void
    {
        $v = $this->variant('SYNC0002', 100);
        $this->sell($v, 8);
        $voided = $this->sell($v, 3);
        $this->voidOrder($voided);               // 3 kembali
        $this->handOver($v, 2);                  // diserahkan: ikut
        $this->createPreorder($v, 5);            // belum diserahkan: TIDAK ikut (Q1 = A)
        $cancelled = $this->createPreorder($v, 4);
        $this->patchJson("/api/v1/preorders/{$cancelled}/status", ['status' => 'cancelled', 'cancel_reason' => 'batal'])->assertOk();
        $this->typeStock($v, 60);

        $row = $this->row($this->plan(), $v);

        $this->assertSame(11, $row['sold']);       // 8 + 3
        $this->assertSame(3, $row['returned']);
        $this->assertSame(2, $row['handed_over']);
        $this->assertSame(10, $row['taken']);      // 11 + 2 − 3
        $this->assertSame(50, $row['new_stock']);
    }

    public function test_transactions_after_the_update_are_not_counted_and_the_operand_is_current_stock(): void
    {
        $v = $this->variant('SYNC0003', 100);
        $this->sell($v, 8);
        $this->typeStock($v, 50);
        $this->sell($v, 2);                        // sesudah pembaruan: sudah mengurangi stok seperti biasa (48)

        $row = $this->row($this->plan(), $v);

        $this->assertSame(48, $row['current_stock']);
        $this->assertSame(8, $row['taken']);       // hanya penjualan SEBELUM pembaruan
        $this->assertSame(40, $row['new_stock']);  // 48 − 8, penjualan susulan tidak terhapus
    }

    public function test_the_window_is_between_the_previous_and_the_latest_manual_update_and_orders_by_id_not_time(): void
    {
        $v = $this->variant('SYNC0004', 100);
        $this->sell($v, 5);                        // sebelum pembaruan pertama
        $this->typeStock($v, 80);                  // pembaruan #1
        $this->sell($v, 7);                        // di antara pembaruan
        $this->typeStock($v, 60);                  // pembaruan #2 (terakhir) — semua dalam detik yang sama

        $row = $this->row($this->plan(), $v);

        $this->assertSame(7, $row['taken']);       // hanya jendela (#1, #2)
        $this->assertSame(53, $row['new_stock']);
        $this->assertNotNull($row['previous_baseline_id']);
        $this->assertStringContainsString('beberapa pembaruan', $row['note']);
    }

    public function test_variants_without_a_manual_update_or_transactions_or_deleted_are_not_changed(): void
    {
        $noUpdate = $this->variant('SYNC0005', 10);
        $this->sell($noUpdate, 2);                 // tanpa pembaruan manual sama sekali
        $noTx = $this->variant('SYNC0006', 10);
        $this->typeStock($noTx, 20);               // pembaruan tanpa transaksi sebelumnya
        $deleted = $this->variant('SYNC0007', 100);
        $this->sell($deleted, 4);
        $this->typeStock($deleted, 50);
        $deleted->delete();

        $plan = $this->plan();

        $this->assertSame('dilewati', $this->row($plan, $noUpdate)['status']);
        $this->assertSame('tidak_ada_perubahan', $this->row($plan, $noTx)['status']);
        $this->assertSame('dilewati', $this->row($plan, $deleted)['status']);   // tanpa galat
        $this->assertSame(0, $this->row($plan, $deleted)['delta']);
    }

    public function test_a_result_below_zero_is_clamped_to_zero_and_the_shortfall_is_reported(): void
    {
        $v = $this->variant('SYNC0008', 100);
        $this->sell($v, 12);
        $this->typeStock($v, 5);

        $row = $this->row($this->plan(), $v);

        $this->assertSame(0, $row['new_stock']);
        $this->assertSame(-5, $row['delta']);
        $this->assertSame(7, $row['shortfall']);
        $this->assertSame('perlu_perhatian', $row['status']);
    }

    public function test_when_stock_is_already_zero_the_shortfall_shows_but_no_movement_is_planned(): void
    {
        $v = $this->variant('SYNC0009', 100);
        $this->sell($v, 12);
        $this->typeStock($v, 0);

        $plan = $this->plan();
        $row = $this->row($plan, $v);

        $this->assertSame(0, $row['delta']);
        $this->assertSame(12, $row['shortfall']);
        $this->assertSame('perlu_perhatian', $row['status']);

        $before = $this->movementCount();
        app(StockSyncApplier::class)->apply($plan, $this->owner);
        $this->assertSame($before, $this->movementCount()); // applyMovement menolak qty nol: tidak ada tulisan
    }

    public function test_demo_data_is_never_part_of_the_plan_even_when_the_system_mode_is_demo(): void
    {
        $live = $this->variant('SYNC0010', 100);
        $this->sell($live, 8);
        $this->typeStock($live, 50);

        $demo = ModeGate::runAs('demo', function () {
            $v = $this->variant('SYNC0011', 100);
            app(StockService::class)->applyMovement($v, 'sale', -9, 'order_item', 1, null, $this->owner->id);
            app(StockService::class)->applyMovement($v, 'adjustment', -40, 'MasterDataImport', null, 'x', $this->owner->id);

            return $v;
        });
        \App\Models\Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo']);
        \Illuminate\Support\Facades\Cache::flush();

        $plan = $this->plan();

        $this->assertNull(collect($plan['rows'])->firstWhere('variant_id', $demo->id));
        $this->assertSame(8, $this->row($plan, $live)['taken']);
    }

    public function test_planning_is_read_only(): void
    {
        $v = $this->variant('SYNC0012', 100);
        $this->sell($v, 8);
        $this->typeStock($v, 50);
        $stocksBefore = ProductVariant::pluck('current_stock', 'id')->all();
        $movementsBefore = $this->movementCount();
        $logsBefore = DB::table('activity_logs')->count();

        $this->plan();

        $this->assertSame($stocksBefore, ProductVariant::pluck('current_stock', 'id')->all());
        $this->assertSame($movementsBefore, $this->movementCount());
        $this->assertSame($logsBefore, DB::table('activity_logs')->count());
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_variants(): void
    {
        $make = function (int $n, string $prefix) {
            foreach (range(1, $n) as $i) {
                $v = $this->variant("{$prefix}{$i}", 100);
                $this->sell($v, 2);
                $this->typeStock($v, 50);
            }
        };
        $make(2, 'QCA');
        $this->plan(); // pemanasan cache pengaturan/lisensi

        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->plan();

            return count(DB::getQueryLog());
        };
        $few = $count();
        $make(8, 'QCB');

        $this->assertSame($few, $count());
    }

    public function test_the_plan_hash_changes_when_any_relevant_number_changes(): void
    {
        $v = $this->variant('SYNC0013', 100);
        $this->sell($v, 8);
        $this->typeStock($v, 50);
        $first = $this->plan()['hash'];

        $this->assertSame($first, $this->plan()['hash']);   // stabil bila tak ada perubahan
        $this->sell($v, 1);                                  // transaksi baru → hash berbeda
        $this->assertNotSame($first, $this->plan()['hash']);
    }

    // --- US2: terapkan ------------------------------------------------------------------------

    public function test_apply_writes_exactly_the_planned_corrections_through_the_ledger(): void
    {
        $v = $this->variant('SYNC0014', 100);
        $this->sell($v, 8);
        $base = $this->typeStock($v, 50);
        $plan = $this->plan();
        $logsBefore = DB::table('activity_logs')->where('action', 'stock_adjusted')->count();

        $result = app(StockSyncApplier::class)->apply($plan, $this->owner);

        $this->assertSame(1, $result['changed']);
        $this->assertSame(42, $v->fresh()->current_stock);
        $m = StockMovement::where('variant_id', $v->id)->latest('id')->first();
        $this->assertSame('adjustment', $m->type);
        $this->assertSame(-8, $m->qty_change);
        $this->assertSame('stock_sync', $m->reference_type);
        $this->assertSame($base->id, $m->reference_id);
        $this->assertSame($this->owner->id, $m->user_id);
        $this->assertSame('live', $m->data_mode);
        $this->assertStringContainsString('8', $m->reason);
        $this->assertSame($logsBefore + 1, DB::table('activity_logs')->where('action', 'stock_adjusted')->count());
    }

    public function test_the_reason_mentions_the_shortfall_when_clamped(): void
    {
        $v = $this->variant('SYNC0015', 100);
        $this->sell($v, 12);
        $this->typeStock($v, 5);

        app(StockSyncApplier::class)->apply($this->plan(), $this->owner);

        $this->assertSame(0, $v->fresh()->current_stock);
        $this->assertStringContainsString('kekurangan 7', StockMovement::where('variant_id', $v->id)->latest('id')->first()->reason);
    }

    public function test_running_again_after_applying_changes_nothing_even_with_sales_between_update_and_fix(): void
    {
        $v = $this->variant('SYNC0016', 100);
        $this->sell($v, 8);
        $this->typeStock($v, 50);
        $this->sell($v, 2);                        // antara pembaruan asli dan koreksi
        app(StockSyncApplier::class)->apply($this->plan(), $this->owner);
        $this->assertSame(40, $v->fresh()->current_stock);

        $again = $this->plan();
        $this->assertSame('sudah_disinkronkan', $this->row($again, $v)['status']);
        $before = $this->movementCount();
        app(StockSyncApplier::class)->apply($again, $this->owner);

        $this->assertSame($before, $this->movementCount());
        $this->assertSame(40, $v->fresh()->current_stock);
        $this->sell($v, 1);                        // transaksi baru tidak membangunkan koreksi lama
        $this->assertSame('sudah_disinkronkan', $this->row($this->plan(), $v)['status']);
    }

    public function test_applying_is_all_or_nothing(): void
    {
        $a = $this->variant('SYNC0017', 100);
        $b = $this->variant('SYNC0018', 100);
        foreach ([$a, $b] as $v) {
            $this->sell($v, 8);
            $this->typeStock($v, 50);
        }
        $plan = $this->plan();
        $this->app->instance(StockService::class, new class(app(\App\Services\ActivityLogger::class)) extends StockService
        {
            public int $calls = 0;

            public function applyMovement(...$args): StockMovement
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('gagal di tengah');
                }

                return parent::applyMovement(...$args);
            }
        });
        $before = $this->movementCount();

        try {
            app(StockSyncApplier::class)->apply($plan, $this->owner);
            $this->fail('diharapkan gagal');
        } catch (\RuntimeException) {
            // diharapkan
        }

        $this->assertSame(50, $a->fresh()->current_stock);
        $this->assertSame(50, $b->fresh()->current_stock);
        $this->assertSame($before, $this->movementCount());
    }

    // --- perintah: persetujuan ----------------------------------------------------------------

    private function scenarioHash(): string
    {
        $v = $this->variant('SYNC0019', 100);
        $this->sell($v, 8);
        $this->typeStock($v, 50);

        return $this->plan()['hash'];
    }

    private function applyCommand(string $hash, array $extra = []): string
    {
        $args = array_merge(['--apply' => true, '--approve' => $hash, '--by' => $this->owner->username], $extra);

        return 'app:sync-stock-from-transactions '.collect($args)->map(fn ($v, $k) => $v === true ? $k : "{$k}={$v}")->implode(' ');
    }

    public function test_the_command_is_a_dry_run_by_default_and_prints_the_hash_and_a_private_file(): void
    {
        $hash = $this->scenarioHash();
        $before = $this->movementCount();

        $this->artisan('app:sync-stock-from-transactions')
            ->expectsOutputToContain($hash)
            ->assertSuccessful();

        $this->assertSame($before, $this->movementCount());
        $this->assertSame([], $this->dumper->calls);
        $files = Storage::disk('local')->files('stock-sync');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('dryrun', $files[0]);
    }

    public function test_apply_without_approval_or_with_a_wrong_or_stale_hash_writes_nothing(): void
    {
        $hash = $this->scenarioHash();
        $before = $this->movementCount();

        $this->artisan("app:sync-stock-from-transactions --apply --by={$this->owner->username}")->assertFailed();
        $this->artisan($this->applyCommand('salah'))->assertFailed();

        $v = ProductVariant::where('sku', 'SYNC0019')->first();
        $this->sell($v, 1);                                    // data berubah sesudah dry-run ditinjau
        $this->artisan($this->applyCommand($hash, ['--yes' => true]))->assertFailed();

        $this->assertSame($before + 1, $this->movementCount()); // hanya penjualan susulan, tanpa koreksi
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_apply_needs_an_authorised_user_and_a_confirmation(): void
    {
        $hash = $this->scenarioHash();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $before = $this->movementCount();

        $this->artisan($this->applyCommand($hash, ['--by' => $cashier->username, '--yes' => true]))->assertFailed();
        $this->artisan($this->applyCommand($hash, ['--by' => 'tidak-ada', '--yes' => true]))->assertFailed();
        $this->artisan($this->applyCommand($hash).' --no-interaction')->assertFailed();           // tanpa --yes
        $this->artisan($this->applyCommand($hash))
            ->expectsConfirmation(SyncStockFromTransactions::CONFIRM_QUESTION, 'no')
            ->assertFailed();

        $this->assertSame($before, $this->movementCount());
        $this->assertSame([], $this->dumper->calls);
    }

    public function test_an_approved_apply_takes_a_safety_backup_first_and_then_writes(): void
    {
        $hash = $this->scenarioHash();

        $this->artisan($this->applyCommand($hash))
            ->expectsConfirmation(SyncStockFromTransactions::CONFIRM_QUESTION, 'yes')
            ->assertSuccessful();

        $this->assertSame(42, ProductVariant::where('sku', 'SYNC0019')->first()->current_stock);
        $this->assertSame('dump', $this->dumper->calls[0][0]);
        $this->assertNotEmpty(array_filter(Storage::disk('local')->files('stock-sync'), fn ($f) => str_contains($f, 'applied')));
    }

    public function test_a_failed_backup_aborts_before_any_write(): void
    {
        $hash = $this->scenarioHash();
        $this->dumper->failDump = true;
        $before = $this->movementCount();

        $this->artisan($this->applyCommand($hash, ['--yes' => true]))->assertFailed();

        $this->assertSame($before, $this->movementCount());
    }

    // --- US3: laporan Excel -------------------------------------------------------------------

    public function test_the_report_has_summary_variants_and_sources_without_customer_data_and_formula_safe_text(): void
    {
        $customer = Customer::factory()->create(['name' => 'Pelanggan Rahasia Satu', 'phone' => '081234567890', 'email' => 'rahasia.satu@example.test']);
        $v = $this->variant('SYNC0020', 100, '=1+1', ['name' => 'Produk Tes']);
        $orderId = $this->sell($v, 8);
        $this->handOver($v, 2, $customer);
        $this->typeStock($v, 5);                                 // klem: terjual 10 > 5 → kekurangan 5
        $hash = $this->plan()['hash'];

        $this->artisan('app:sync-stock-from-transactions')->assertSuccessful();

        $file = Storage::disk('local')->path(Storage::disk('local')->files('stock-sync')[0]);
        $book = IOFactory::load($file);
        $this->assertSame(['Ringkasan', 'Varian', 'Sumber'], $book->getSheetNames());

        $all = '';
        foreach ($book->getAllSheets() as $sheet) {
            $all .= json_encode($sheet->toArray(null, true, false, false));
        }
        $this->assertStringContainsString($hash, $all);
        $this->assertStringContainsString('SYNC0020', $all);
        $this->assertStringContainsString('perlu_perhatian', $all);
        $this->assertStringContainsString(\App\Models\Order::findOrFail($orderId)->order_number, $all);   // lembar Sumber
        foreach (['Pelanggan Rahasia Satu', '081234567890', 'rahasia.satu@example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, $all);
        }

        $cell = null;
        foreach ($book->getSheetByName('Varian')->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $c) {
                if ($c->getValue() === '=1+1') {
                    $cell = $c;
                }
            }
        }
        $this->assertNotNull($cell, 'nama varian "=1+1" harus ada di lembar Varian');
        $this->assertSame('s', $cell->getDataType());
    }

    public function test_the_report_is_stored_on_the_private_disk_only(): void
    {
        $this->scenarioHash();
        $this->artisan('app:sync-stock-from-transactions')->assertSuccessful();

        $this->assertNotEmpty(Storage::disk('local')->files('stock-sync'));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }
}
