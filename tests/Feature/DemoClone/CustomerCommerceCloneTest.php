<?php

namespace Tests\Feature\DemoClone;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Services\DemoClone\CloneException;
use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveFingerprint;
use App\Services\DemoClone\LiveToDemoCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

/**
 * Customer commerce × app:clone-live-to-demo.
 *
 * Keputusan (CONTRACT §4): enam tabel pesanan pelanggan HANYA DIHAPUS di sisi
 * DEMO, tidak dicerminkan dari LIVE — mencerminkan berarti menyalin hash kata
 * sandi dan data pribadi akun ke DEMO. Yang dijaga di sini:
 *  - DEMO lama (termasuk token akunnya) hilang, klon tetap berhasil;
 *  - baris LIVE keenam tabel + token akun LIVE identik sebelum/sesudah;
 *  - baris LIVE yang menunjuk induk DEMO menghentikan proses SEBELUM ada yang
 *    dihapus (FK RESTRICT akan gagal dengan galat mentah; CASCADE/SET NULL
 *    akan diam-diam mengubah LIVE);
 *  - produk tetap tercermin walau kini punya slug unik per mode.
 */
class CustomerCommerceCloneTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private const SIX = [
        'customer_order_status_events', 'customer_order_shipments', 'customer_order_items',
        'customer_orders', 'customer_addresses', 'customer_accounts',
    ];

    private array $live;

    private array $oldDemo;

    private array $liveCc;

    private array $demoCc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->live = $this->buildGraph('live');
        $this->oldDemo = $this->buildGraph('demo');
        $this->liveCc = $this->buildCustomerCommerceGraph('live', $this->live);
        $this->demoCc = $this->buildCustomerCommerceGraph('demo', $this->oldDemo);
    }

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

    private function rows(string $table, string $mode): int
    {
        return DB::table($table)->whereRaw(CloneRegistry::wipeOnlyScope($table, $mode))->count();
    }

    private function accountTokens(int $accountId): int
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', (new CustomerAccount)->getMorphClass())
            ->where('tokenable_id', $accountId)
            ->count();
    }

    /** Menjalankan klon yang HARUS berhenti sebelum menghapus apa pun, lalu membuktikan tidak ada yang berubah. */
    private function assertAbortsBeforeWipe(string $needle): void
    {
        $before = LiveFingerprint::capture();

        try {
            (new LiveToDemoCloner)->run();
            $this->fail('Seharusnya CloneException');
        } catch (CloneException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            $this->assertStringContainsString('tidak ada yang diubah', $e->getMessage());
        }

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));
        $this->assertTrue(DB::table('artists')->where('code', 'DMO')->exists(), 'DEMO tidak boleh dihapus');
        $this->assertSame(1, $this->rows('customer_orders', 'demo'), 'Pesanan pelanggan DEMO tidak boleh dihapus');
        $this->assertSame(1, $this->accountTokens($this->demoCc['account']), 'Token akun DEMO tidak boleh dihapus');
    }

    // ------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------

    public function test_the_six_tables_are_wipe_only_child_first_and_never_mirrored(): void
    {
        $wipeOnly = CloneRegistry::wipeOnly();

        $this->assertSame(self::SIX, array_keys($wipeOnly));
        $this->assertSame([], array_intersect(array_keys($wipeOnly), array_keys(CloneRegistry::tables())), 'Tabel wipe-only tidak boleh ikut dicerminkan.');

        // Anak selalu sebelum induknya: urutan ini dipakai apa adanya untuk DELETE.
        $order = array_keys($wipeOnly);
        foreach ($wipeOnly as $table => $via) {
            if ($via !== null) {
                $parent = explode('.', $via)[0];
                $this->assertLessThan(array_search($parent, $order, true), array_search($table, $order, true), "{$table} harus dihapus sebelum {$parent}");
            }
        }
        $this->assertLessThan(array_search('customer_accounts', $order, true), array_search('customer_orders', $order, true));

        $this->assertSame("data_mode = 'demo'", CloneRegistry::wipeOnlyScope('customer_orders', 'demo'));
        $this->assertSame(
            "customer_order_id IN (SELECT id FROM customer_orders WHERE data_mode = 'live')",
            CloneRegistry::wipeOnlyScope('customer_order_items', 'live'),
        );
        $this->assertSame(
            "customer_account_id IN (SELECT id FROM customer_accounts WHERE data_mode = 'demo')",
            CloneRegistry::wipeOnlyScope('customer_addresses', 'demo'),
        );

        $this->assertSame([
            'customer_accounts.customer_id' => 'customers',
            'customer_orders.customer_id' => 'customers',
            'customer_orders.customer_account_id' => 'customer_accounts',
            'customer_order_items.variant_id' => 'product_variants',
        ], CloneRegistry::wipeOnlyReferences());
    }

    public function test_wipe_only_scope_rejects_anything_but_a_literal_mode_or_a_known_table(): void
    {
        foreach ([['customer_orders', "live' OR 1=1 --"], ['orders', 'live']] as [$table, $mode]) {
            try {
                CloneRegistry::wipeOnlyScope($table, $mode);
                $this->fail('Seharusnya InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_plan_lists_demo_rows_to_delete_and_zero_rows_to_clone(): void
    {
        $plan = (new LiveToDemoCloner)->plan();

        foreach (self::SIX as $table) {
            $this->assertSame(['demo_delete' => 1, 'live_clone' => 0], $plan['tables'][$table], $table);
        }
        // Tidak menulis apa pun.
        $this->assertSame(1, $this->rows('customer_orders', 'demo'));
    }

    // ------------------------------------------------------------------
    // Klon berhasil
    // ------------------------------------------------------------------

    public function test_demo_customer_commerce_rows_are_wiped_and_live_ones_are_untouched(): void
    {
        $staffToken = $this->row('personal_access_tokens', [
            'tokenable_type' => (new User)->getMorphClass(), 'tokenable_id' => $this->live['user'],
            'name' => 'pos-session', 'token' => hash('sha256', 'token-staf'), 'abilities' => '["*"]',
        ]);
        $before = LiveFingerprint::capture();
        $liveRows = [];
        foreach (self::SIX as $table) {
            $liveRows[$table] = DB::table($table)->whereRaw(CloneRegistry::wipeOnlyScope($table, 'live'))->get()->all();
        }

        $report = (new LiveToDemoCloner)->run();

        foreach (self::SIX as $table) {
            $this->assertSame(0, $this->rows($table, 'demo'), "{$table}: baris DEMO harus terhapus dan TIDAK disalin dari LIVE");
            $this->assertEquals(
                $liveRows[$table],
                DB::table($table)->whereRaw(CloneRegistry::wipeOnlyScope($table, 'live'))->get()->all(),
                "{$table}: baris LIVE harus identik",
            );
            $this->assertSame(1, $report['deleted'][$table], $table);
            $this->assertArrayNotHasKey($table, $report['cloned']);
        }

        $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()));

        // Token: milik akun DEMO terhapus (tabel polimorfik tanpa FK, tidak ikut cascade); LIVE dan staf tetap.
        $this->assertSame(0, $this->accountTokens($this->demoCc['account']));
        $this->assertSame(1, $this->accountTokens($this->liveCc['account']));
        $this->assertTrue(DB::table('personal_access_tokens')->where('id', $staffToken)->exists());
        $this->assertSame(1, $report['deleted']['personal_access_tokens']);

        // Tabel yang dicerminkan tetap utuh: pelanggan dan varian DEMO baru ada (hasil salin LIVE).
        $this->assertSame(1, DB::table('customers')->where('data_mode', 'demo')->count());
        $this->assertSame(2, DB::table('product_variants')->where('data_mode', 'demo')->count());
    }

    public function test_a_second_run_is_stable(): void
    {
        (new LiveToDemoCloner)->run();
        $report = (new LiveToDemoCloner)->run();

        foreach (self::SIX as $table) {
            $this->assertSame(0, $report['deleted'][$table], $table);
            $this->assertSame(1, $this->rows($table, 'live'), $table);
        }
    }

    public function test_a_live_stock_movement_of_a_customer_order_is_mirrored_without_leaking_the_live_item_id(): void
    {
        $report = (new LiveToDemoCloner)->run();

        $demo = DB::table('stock_movements')->where('data_mode', 'demo')->where('reference_type', 'customer_order_item')->first();

        $this->assertNotNull($demo, 'Riwayat stok tetap dicerminkan supaya stok DEMO sama dengan LIVE.');
        $this->assertNull($demo->reference_id, 'Item pesanan pelanggan tidak disalin: id LIVE tidak boleh menempel di baris DEMO.');
        $this->assertGreaterThanOrEqual(1, $report['references_dropped']);
    }

    public function test_products_are_mirrored_with_their_slug_and_publication_even_when_both_modes_share_a_slug(): void
    {
        // Sisa klon sebelumnya: produk DEMO lama memakai slug yang sama dengan LIVE (unik hanya per mode).
        DB::table('products')->where('id', $this->oldDemo['product'])->update(['slug' => 'slug-yang-sama']);
        DB::table('products')->where('id', $this->live['product'])->update(['slug' => 'slug-yang-sama', 'published_at' => '2026-11-01 09:00:00']);
        DB::table('product_variants')->where('id', $this->live['variant1'])->update(['published_at' => '2026-11-02 09:00:00']);

        (new LiveToDemoCloner)->run();

        $demo = DB::table('products')->where('data_mode', 'demo')->get();
        $this->assertCount(1, $demo);
        $this->assertSame('slug-yang-sama', $demo[0]->slug);
        $this->assertSame('2026-11-01 09:00:00', $demo[0]->published_at);
        $this->assertSame('slug-yang-sama', DB::table('products')->where('id', $this->live['product'])->value('slug'));

        $this->assertEqualsCanonicalizing(
            ['2026-11-02 09:00:00', null],
            DB::table('product_variants')->where('data_mode', 'demo')->pluck('published_at')->all(),
        );
    }

    // ------------------------------------------------------------------
    // Berhenti sebelum menghapus: LIVE menunjuk DEMO
    // ------------------------------------------------------------------

    public function test_a_live_customer_order_item_pointing_at_a_demo_variant_aborts_before_anything_is_wiped(): void
    {
        DB::table('customer_order_items')->where('id', $this->liveCc['item'])->update(['variant_id' => $this->oldDemo['variant1']]);

        $this->assertAbortsBeforeWipe('customer_order_items.variant_id');

        $this->assertSame($this->oldDemo['variant1'], (int) DB::table('customer_order_items')->where('id', $this->liveCc['item'])->value('variant_id'));
    }

    public function test_a_live_customer_account_pointing_at_a_demo_customer_aborts_before_anything_is_wiped(): void
    {
        $demoCustomer = $this->row('customers', ['data_mode' => 'demo', 'name' => 'Customer DEMO Kedua']);
        DB::table('customer_accounts')->where('id', $this->liveCc['account'])->update(['customer_id' => $demoCustomer]);

        $this->assertAbortsBeforeWipe('customer_accounts.customer_id');
    }

    public function test_a_live_customer_order_pointing_at_a_demo_customer_aborts_before_anything_is_wiped(): void
    {
        DB::table('customer_orders')->where('id', $this->liveCc['order'])->update(['customer_id' => $this->oldDemo['customer']]);

        $this->assertAbortsBeforeWipe('customer_orders.customer_id');
    }

    public function test_a_live_customer_order_pointing_at_a_demo_account_aborts_before_anything_is_wiped(): void
    {
        DB::table('customer_orders')->where('id', $this->liveCc['order'])->update(['customer_account_id' => $this->demoCc['account']]);

        $this->assertAbortsBeforeWipe('customer_orders.customer_account_id');
    }

    // ------------------------------------------------------------------
    // Sidik jari LIVE dan pemeriksaan akhir
    // ------------------------------------------------------------------

    public function test_any_change_to_live_customer_commerce_rows_during_the_run_aborts_and_rolls_back(): void
    {
        $tamper = [
            'customer_accounts' => fn () => DB::table('customer_accounts')->where('id', $this->liveCc['account'])->update(['last_login_at' => now()]),
            'customer_addresses' => fn () => DB::table('customer_addresses')->where('id', $this->liveCc['address'])->update(['label' => 'Diubah']),
            'customer_orders' => fn () => DB::table('customer_orders')->where('id', $this->liveCc['order'])->update(['notes' => 'Diubah']),
            'customer_order_items' => fn () => DB::table('customer_order_items')->where('id', $this->liveCc['item'])->update(['qty' => 9]),
            'customer_order_shipments' => fn () => DB::table('customer_order_shipments')->where('id', $this->liveCc['shipment'])->update(['tracking_number' => 'DIUBAH']),
            'customer_order_status_events' => fn () => DB::table('customer_order_status_events')->where('id', $this->liveCc['event'])->delete(),
            'personal_access_tokens' => fn () => DB::table('personal_access_tokens')->where('id', $this->liveCc['token'])->delete(),
        ];
        $before = LiveFingerprint::capture();

        foreach ($tamper as $table => $hook) {
            $this->assertArrayHasKey($table, $before);

            try {
                $this->clonerWithHook($hook)->run();
                $this->fail("Perubahan LIVE di {$table} seharusnya membatalkan proses.");
            } catch (CloneException $e) {
                $this->assertStringContainsString('LIVE berubah', $e->getMessage());
                $this->assertStringContainsString($table, $e->getMessage());
            }

            $this->assertSame([], LiveFingerprint::diff($before, LiveFingerprint::capture()), "{$table}: LIVE harus kembali seperti semula");
            $this->assertSame(1, $this->rows('customer_orders', 'demo'), 'DEMO lama harus kembali');
        }
    }

    public function test_demo_customer_commerce_rows_left_after_the_wipe_fail_the_integrity_check(): void
    {
        // Baris DEMO yang muncul lagi di tabel wipe-only (mis. wipe terlewat) tidak boleh lolos diam-diam.
        $cloner = $this->clonerWithHook(function () {
            $customer = DB::table('customers')->where('data_mode', 'demo')->value('id');
            $this->row('customer_accounts', [
                'data_mode' => 'demo', 'customer_id' => $customer, 'email' => 'sisa@example.test', 'password' => 'sintetis-bukan-hash',
            ]);
        });

        $this->expectException(CloneException::class);
        $this->expectExceptionMessage('customer_accounts');

        $cloner->run();
    }
}
