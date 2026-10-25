<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VariantOutletStock;
use App\Services\OutletMenuService;
use App\Services\PriceService;
use App\Support\ModeGate;
use App\Support\WjmCatalogSeedReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 068 US3 (FR-015, FR-016, FR-018, FR-019, FR-021, SC-003, SC-005) — jalan ulang tidak membuat atau mengubah
 * apa pun, kegagalan satu mode tidak meninggalkan sisa, dan laporan mempertanggungjawabkan setiap baris sumber.
 */
class WjmCatalogSeedRerunTest extends TestCase
{
    use RefreshDatabase;
    use WjmCatalogFixtures;

    private const CATALOG_TABLES = ['categories', 'products', 'product_variants', 'modifier_groups', 'outlet_item_settings', 'stations'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function lastUpdates(): array
    {
        return collect(self::CATALOG_TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->max('updated_at')])->all();
    }

    private function kopiIce(): ProductVariant
    {
        return ProductVariant::whereHas('product', fn ($q) => $q->where('name', 'Kopi Uji'))->where('variant_name', 'Ice')->firstOrFail();
    }

    public function test_a_second_run_creates_nothing_and_changes_nothing(): void
    {
        $this->wjmRun(['live', 'demo']);
        $counts = $this->rowCounts();
        $updates = $this->lastUpdates();
        // Bila jalan ulang menyentuh baris apa pun, updated_at-nya pasti lebih baru dari catatan di atas.
        $this->travel(5)->minutes();

        $reports = $this->wjmRun(['live', 'demo']);

        foreach ($reports as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
            foreach ($report->counts as $kind => $count) {
                $this->assertSame(0, $count['created'], "{$report->mode}: {$kind}");
            }
            $this->assertSame(
                ['stations' => 2, 'categories' => 3, 'items' => 7, 'variants' => 11, 'option_groups' => 1, 'photos' => 2, 'stock' => 0],
                array_map(fn (array $c) => $c['existing'], $report->counts),
            );
        }
        // Satu entri audit per mode yang berhasil (FR-019); data katalog tidak bertambah sebaris pun.
        $this->assertSame(array_merge($counts, ['activity_logs' => $counts['activity_logs'] + 2]), $this->rowCounts());
        $this->assertSame($updates, $this->lastUpdates());
    }

    public function test_owner_changes_to_price_and_photo_survive_a_rerun(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->wjmRun(['live']);
        $owner = User::withoutGlobalScopes()->where('username', 'owner')->firstOrFail();
        ModeGate::runAs('live', function () use ($owner) {
            $variant = $this->kopiIce();
            app(PriceService::class)->apply($variant, (float) $variant->sell_price, 11000, $owner, 'edit');
            Product::where('name', 'Roti Bakar Keju')->firstOrFail()->update(['image_path' => 'products/foto-pemilik.jpg']);
        });

        $this->wjmRun(['live']);

        ModeGate::runAs('live', function () {
            $this->assertSame(11000.0, (float) $this->kopiIce()->sell_price);
            $this->assertSame('products/foto-pemilik.jpg', Product::where('name', 'Roti Bakar Keju')->value('image_path'));
        });
    }

    public function test_an_item_deleted_by_the_owner_is_not_recreated_and_is_reported(): void
    {
        $this->wjmRun(['live']);
        ModeGate::runAs('live', fn () => Product::where('name', 'Kit Uji')->firstOrFail()->delete());

        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame(['Kit Uji'], $report->deletedByOwner);
        $this->assertSame(0, $report->counts['items']['created']);
        $this->assertSame(1, ModeGate::runAs('live', fn () => Product::withTrashed()->where('name', 'Kit Uji')->count()));
    }

    public function test_an_item_renamed_by_the_owner_is_created_again_on_a_rerun(): void
    {
        $this->wjmRun(['live']);
        ModeGate::runAs('live', fn () => Product::where('name', 'Nasi Putih Uji')->firstOrFail()->update(['name' => 'Nasi Putih']));

        $report = $this->wjmRun(['live'])['live'];

        // Edge case spec: pencocokan memakai nama + kategori, jadi item yang diganti nama dianggap belum ada.
        $this->assertSame(1, $report->counts['items']['created']);
        $this->assertSame(1, ModeGate::runAs('live', fn () => Product::where('name', 'Nasi Putih Uji')->count()));
    }

    public function test_a_failure_in_one_mode_rolls_that_mode_back_completely_and_keeps_the_other(): void
    {
        $real = app(OutletMenuService::class);
        $this->partialMock(OutletMenuService::class, function ($mock) use ($real) {
            $mock->shouldReceive('setCarried')->andReturnUsing(function (...$args) use ($real) {
                if (ModeGate::isDemo()) {
                    throw new \RuntimeException('gagal uji');
                }

                return $real->setCarried(...$args);
            });
        });

        $reports = $this->wjmRun(['live', 'demo']);

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $reports['live']->status);
        $this->assertSame(WjmCatalogSeedReport::FAILED, $reports['demo']->status);
        $this->assertStringContainsString('gagal uji', $reports['demo']->problems[0]);
        $this->assertSame(0, $reports['demo']->counts['items']['created'], 'jumlah dari jalan yang dibatalkan tidak boleh terbaca tersimpan');
        ModeGate::runAs('demo', function () {
            $this->assertSame(0, Outlet::count(), 'outlet DEMO yang sempat dibuat ikut dibatalkan');
            $this->assertSame(0, Category::count());
            $this->assertSame(0, Product::withTrashed()->count());
            $this->assertSame(0, ProductVariant::count());
            $this->assertSame(0, ModifierGroup::count());
        });
        $this->assertSame(7, ModeGate::runAs('live', fn () => Product::count()));
        $this->assertSame(1, ActivityLog::where('action', 'catalog_seeded')->count(), 'hanya mode yang berhasil yang tercatat');
        $this->assertCount(2, Storage::disk('public')->allFiles('products'), 'foto DEMO yang sempat ditulis sudah dihapus; foto LIVE tetap');
    }

    public function test_the_report_accounts_for_every_source_row(): void
    {
        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame([
            'Partial Payment Uji — Produk teknis Odoo (nf_partial_payment), bukan menu',
            'Lama — Tidak dipakai item mana pun',
            'Lama: test — Data uji',
        ], $report->excluded);
        $this->assertContains('Nasi Putih Uji (kosong)', $report->withoutPhoto);
        $this->assertContains('Kit Uji (terpotong di ekspor XLSX)', $report->withoutPhoto);
        $this->assertSame(['Nasi Putih Uji'], $report->zeroPrice);
        $this->assertSame(['Kit Uji'], $report->kitItems);
        $this->assertSame([
            'produk 8 = 7 dibuat + 0 sudah ada + 1 dikecualikan',
            'kategori 3 = 3 dibuat + 0 sudah ada + 0 dikecualikan',
            'nilai atribut 9 = 6 dipakai varian + 2 opsi grup + 1 dikecualikan',
        ], $report->sourceAccounting);
    }

    /** 069: outlet pertama (katalog mini 068) terisi di kedua mode, lalu outlet kedua diisi di kedua mode. */
    private function seedBothOutlets(): string
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $this->wjmRun(['live', 'demo']);
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));
        $second = $this->wjmCatalogPath(null, 'wjm2-catalog');
        foreach ($this->wjmRun(['live', 'demo'], path: $second) as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        }

        return $second;
    }

    public function test_a_second_run_for_the_second_outlet_creates_nothing_and_adds_no_stock(): void
    {
        $second = $this->seedBothOutlets();
        $counts = $this->rowCounts();

        $reports = $this->wjmRun(['live', 'demo'], path: $second);

        foreach ($reports as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
            foreach ($report->counts as $kind => $count) {
                $this->assertSame(0, $count['created'], "{$report->mode}: {$kind}");
            }
            $this->assertSame(3, $report->counts['items']['existing'], $report->mode);
            $this->assertSame([], $report->withdrawn, "{$report->mode}: tidak ada lagi yang perlu ditarik");
        }
        $this->assertSame(array_merge($counts, ['activity_logs' => $counts['activity_logs'] + 2]), $this->rowCounts());
        foreach (['live', 'demo'] as $mode) {
            ModeGate::runAs($mode, function () use ($mode) {
                $b = Outlet::where('name', 'Warkop Uji 2')->firstOrFail();
                $variants = ProductVariant::whereIn('product_id', Product::where('home_outlet_id', $b->id)->pluck('id'))->get();
                $this->assertCount(5, $variants, $mode);
                foreach ($variants as $variant) {
                    $this->assertSame(100, (int) VariantOutletStock::where('variant_id', $variant->id)->where('outlet_id', $b->id)->value('current_stock'), "{$mode}: stok tidak menumpuk");
                    $this->assertSame(1, StockMovement::where('variant_id', $variant->id)->count(), "{$mode}: satu gerakan stok awal per varian");
                }
            });
        }
    }

    public function test_owner_changes_to_the_second_outlets_items_survive_a_rerun_and_a_deleted_item_is_not_recreated(): void
    {
        $second = $this->seedBothOutlets();
        ModeGate::runAs('live', function () {
            $b = Outlet::where('code', 'WJD')->firstOrFail();
            $jus = Product::where('home_outlet_id', $b->id)->where('name', 'Jus Uji')->firstOrFail();
            $jus->variants()->orderBy('id')->firstOrFail()->forceFill(['sell_price' => 12345])->save();
            Product::where('home_outlet_id', $b->id)->where('name', 'Keripik Uji')->firstOrFail()->delete();
        });

        $report = $this->wjmRun(['live'], path: $second)['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(0, $report->counts['items']['created']);
        $this->assertSame(['Keripik Uji'], $report->deletedByOwner);
        ModeGate::runAs('live', function () {
            $b = Outlet::where('code', 'WJD')->firstOrFail();
            $this->assertSame(12345.0, (float) Product::where('home_outlet_id', $b->id)->where('name', 'Jus Uji')->firstOrFail()->variants()->orderBy('id')->firstOrFail()->sell_price);
            $this->assertSame(0, Product::where('home_outlet_id', $b->id)->where('name', 'Keripik Uji')->count());
        });
    }

    public function test_rerunning_the_first_catalog_after_the_second_neither_adopts_nor_changes_the_second_outlets_items(): void
    {
        $this->seedBothOutlets();
        $counts = $this->rowCounts();

        $reports = $this->wjmRun(['live', 'demo']);

        foreach ($reports as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
            foreach ($report->counts as $kind => $count) {
                $this->assertSame(0, $count['created'], "{$report->mode}: {$kind}");
            }
            $this->assertSame(7, $report->counts['items']['existing'], "{$report->mode}: hanya item outlet pertama");
            $this->assertSame([], $report->withdrawn, "{$report->mode}: item outlet kedua sudah tidak dijual di outlet pertama");
        }
        $this->assertSame(array_merge($counts, ['activity_logs' => $counts['activity_logs'] + 2]), $this->rowCounts());
    }
}
