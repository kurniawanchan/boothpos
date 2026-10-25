<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\OutletItemSetting;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\VariantOutletStock;
use App\Services\OutletMenuService;
use App\Support\ModeGate;
use App\Support\WjmCatalogSeedReport;
use Database\Seeders\Wjm2CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 069 — katalog outlet KEDUA diisi di samping katalog outlet pertama (katalog mini 068 = A, katalog mini 069 = B).
 * US1: item B milik B, hanya dijual di B, stasiun B, stok 100. US2/US3: data A tidak berubah dan DEMO membuat B.
 */
class WjmCatalogSecondOutletTest extends TestCase
{
    use RefreshDatabase;
    use WjmCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function second(): string
    {
        return $this->wjmCatalogPath(null, 'wjm2-catalog');
    }

    /** Outlet A terisi katalog mini pertama di LIVE; outlet B ("Warkop Uji 2") ada tanpa item sendiri. */
    private function prepareLive(): array
    {
        $first = $this->wjmRun(['live'])['live'];
        $this->assertSame(WjmCatalogSeedReport::APPLIED, $first->status, implode(' | ', $first->problems));
        $a = ModeGate::runAs('live', fn () => Outlet::where('code', 'WJ3')->firstOrFail());
        $b = ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));

        return [$a, $b];
    }

    public function test_the_second_catalog_creates_items_owned_by_the_second_outlet_even_when_names_collide(): void
    {
        [$a, $b] = $this->prepareLive();

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame([3, 0], [$report->counts['items']['created'], $report->counts['items']['existing']], '"Kopi Uji" milik A tidak boleh dianggap sudah ada');
        ModeGate::runAs('live', function () use ($a, $b) {
            $mine = Product::where('home_outlet_id', $b->id)->orderBy('id')->get();
            $this->assertSame(['Kopi Uji', 'Keripik Uji', 'Jus Uji'], $mine->pluck('name')->all());
            $this->assertSame(1, Product::where('home_outlet_id', $a->id)->where('name', 'Kopi Uji')->count(), 'item A tidak digandakan');

            $menu = app(OutletMenuService::class);
            foreach ($mine as $product) {
                $this->assertTrue($menu->isCarried($product->id, $b->id), $product->name);
                $this->assertFalse($menu->isCarried($product->id, $a->id), $product->name);
            }
        });
    }

    public function test_each_new_item_gets_the_second_outlets_station_for_its_role_while_the_shared_category_keeps_the_first_outlets(): void
    {
        [$a, $b] = $this->prepareLive();

        $this->wjmRun(['live'], path: $this->second());

        ModeGate::runAs('live', function () use ($a, $b) {
            $stationOf = fn (Outlet $o, string $name) => Station::withoutGlobalScopes()->where('outlet_id', $o->id)->where('name', $name)->value('id');
            $this->assertNotNull($stationOf($b, 'Bar'));
            $this->assertNotNull($stationOf($b, 'Dapur'));

            $byName = Product::where('home_outlet_id', $b->id)->get()->keyBy('name');
            $this->assertSame($stationOf($b, 'Bar'), $byName['Kopi Uji']->station_id);
            $this->assertSame($stationOf($b, 'Bar'), $byName['Jus Uji']->station_id);
            $this->assertSame($stationOf($b, 'Dapur'), $byName['Keripik Uji']->station_id);

            $shared = Category::where('name', 'Kopi Uji')->get();
            $this->assertCount(1, $shared, 'kategori yang sudah ada dipakai bersama, tidak digandakan');
            $this->assertSame($stationOf($a, 'Bar'), $shared->first()->station_id, 'stasiun kategori outlet pertama tidak boleh berubah');

            $new = Category::where('name', 'Camilan Uji 2')->firstOrFail();
            $this->assertSame($stationOf($b, 'Dapur'), $new->station_id);
        });
    }

    public function test_every_new_variant_tracks_stock_and_starts_at_the_initial_stock_in_the_second_outlet_only(): void
    {
        [$a, $b] = $this->prepareLive();

        $this->wjmRun(['live'], path: $this->second());

        ModeGate::runAs('live', function () use ($a, $b) {
            $variants = ProductVariant::whereIn('product_id', Product::where('home_outlet_id', $b->id)->pluck('id'))->get();
            $this->assertCount(5, $variants);
            foreach ($variants as $variant) {
                $this->assertTrue((bool) $variant->track_stock, $variant->sku);
                $this->assertSame(100, (int) VariantOutletStock::where('variant_id', $variant->id)->where('outlet_id', $b->id)->value('current_stock'), $variant->sku);
                $this->assertSame(0, VariantOutletStock::where('variant_id', $variant->id)->where('outlet_id', $a->id)->count(), $variant->sku);
                $this->assertSame(['initial'], StockMovement::where('variant_id', $variant->id)->pluck('type')->all(), $variant->sku);
            }
        });
    }

    public function test_values_that_come_from_the_mapping_table_become_variants(): void
    {
        [, $b] = $this->prepareLive();

        $this->wjmRun(['live'], path: $this->second());

        ModeGate::runAs('live', function () use ($b) {
            $jus = Product::where('home_outlet_id', $b->id)->where('name', 'Jus Uji')->firstOrFail();
            $this->assertSame(['Jambu', 'Mangga'], $jus->variants()->orderBy('id')->pluck('variant_name')->all());
            $this->assertSame([7000.0, 7000.0], $jus->variants()->orderBy('id')->get()->map(fn ($v) => (float) $v->sell_price)->all());
        });
    }

    public function test_a_source_category_without_items_is_not_created_but_is_reported_as_excluded(): void
    {
        $this->prepareLive();

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        ModeGate::runAs('live', fn () => $this->assertSame(0, Category::where('name', 'MINUMAN KOSONG')->count()));
        $this->assertTrue(collect($report->excluded)->contains(fn (string $line) => str_contains($line, 'MINUMAN KOSONG')), implode(' | ', $report->excluded));
    }

    /**
     * Potret semua baris yang MILIK outlet pertama pada satu mode: item, varian, riwayat harga, saldo dan gerakan stok,
     * penanda menu di outlet itu untuk item-itemnya sendiri, kategori, stasiun, dan grup pilihan (research D13).
     * Kolom `updated_at` ikut dibandingkan, jadi menyentuh baris tanpa mengubah isinya pun terdeteksi.
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(string $mode, Outlet $outlet): array
    {
        $rows = fn ($query) => $query->get()->map(fn ($m) => $m->getAttributes())->all();

        return ModeGate::runAs($mode, function () use ($outlet, $rows) {
            $products = Product::withTrashed()->where('home_outlet_id', $outlet->id)->orderBy('id');
            $productIds = (clone $products)->pluck('id');
            $variants = ProductVariant::withTrashed()->whereIn('product_id', $productIds)->orderBy('id');
            $variantIds = (clone $variants)->pluck('id');

            return [
                'products' => $rows($products),
                'variants' => $rows($variants),
                'price_history' => $rows(PriceHistory::whereIn('variant_id', $variantIds)->orderBy('id')),
                'stock' => $rows(VariantOutletStock::whereIn('variant_id', $variantIds)->orderBy('variant_id')->orderBy('outlet_id')),
                'movements' => $rows(StockMovement::whereIn('variant_id', $variantIds)->orderBy('id')),
                'menu_here' => $rows(OutletItemSetting::whereIn('product_id', $productIds)->where('outlet_id', $outlet->id)->orderBy('id')),
                'categories' => $rows(Category::withTrashed()->whereIn('id', Product::withTrashed()->whereIn('id', $productIds)->pluck('category_id'))->orderBy('id')),
                'stations' => $rows(Station::withoutGlobalScopes()->where('outlet_id', $outlet->id)->orderBy('id')),
                'groups' => ModifierGroup::with('options')->orderBy('id')->get()->map(fn ($g) => [$g->getAttributes(), $g->options->map->getAttributes()->all(), $g->products()->pluck('products.id')->all()])->all(),
                'outlet' => $outlet->fresh()?->getAttributes(),
            ];
        });
    }

    public function test_seeding_the_second_outlet_changes_nothing_of_the_first_outlet_in_either_mode(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $first = $this->wjmRun(['live', 'demo']);
        foreach ($first as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        }
        $a = [];
        foreach (['live', 'demo'] as $mode) {
            $a[$mode] = ModeGate::runAs($mode, fn () => Outlet::orderBy('id')->firstOrFail());
        }
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));
        $this->travel(10)->minutes();
        $before = ['live' => $this->snapshotOf('live', $a['live']), 'demo' => $this->snapshotOf('demo', $a['demo'])];
        $this->assertNotEmpty($before['live']['products']);

        $reports = $this->wjmRun(['live', 'demo'], path: $this->second());

        foreach ($reports as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        }
        $this->assertSame($before['live'], $this->snapshotOf('live', $a['live']), 'LIVE: data outlet pertama berubah');
        $this->assertSame($before['demo'], $this->snapshotOf('demo', $a['demo']), 'DEMO: data outlet pertama berubah');
    }

    public function test_the_first_outlets_items_are_withdrawn_from_the_second_outlets_menu_in_both_modes_and_still_sold_at_the_first(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $this->wjmRun(['live', 'demo']);
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));

        $reports = $this->wjmRun(['live', 'demo'], path: $this->second());

        $menu = app(OutletMenuService::class);
        foreach (['live', 'demo'] as $mode) {
            $this->assertCount(7, $reports[$mode]->withdrawn, "{$mode}: 7 item outlet pertama ditarik dari menu outlet kedua");
            ModeGate::runAs($mode, function () use ($menu, $mode) {
                $a = Outlet::orderBy('id')->firstOrFail();
                $b = Outlet::where('name', 'Warkop Uji 2')->firstOrFail();
                $theirs = Product::where('home_outlet_id', $a->id)->get();
                $this->assertCount(7, $theirs, $mode);
                foreach ($theirs as $product) {
                    $this->assertFalse($menu->isCarried($product->id, $b->id), "{$mode}: {$product->name} masih dijual di outlet kedua");
                    $this->assertTrue($menu->isCarried($product->id, $a->id), "{$mode}: {$product->name} tidak lagi dijual di outlet pertama");
                }
            });
        }
    }

    /**
     * Regresi 2026-10-25: pemilik membuat item sendiri di outlet WJM 2 (LIVE) lewat layar manajemen. Jalan ulang
     * katalog tidak boleh menyembunyikannya dari POS; item outlet pertama tetap ditarik.
     */
    public function test_a_live_run_keeps_items_the_owner_made_at_the_second_outlet_but_still_withdraws_the_first_outlets(): void
    {
        [$a, $b] = $this->prepareLive();
        $menu = app(OutletMenuService::class);
        $own = ModeGate::runAs('live', function () use ($b, $menu) {
            $own = Product::factory()->create([
                'home_outlet_id' => $b->id,
                'category_id' => Category::factory()->create(['code' => 'ZZ', 'name' => 'Buatan Pemilik'])->id,
                'code_prefix' => 'WJD-ZZ-AAA',
                'name' => 'Item Buatan Pemilik',
            ]);
            $menu->setCarried($own, [$b->id]);

            return $own;
        });

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertNotContains('Item Buatan Pemilik', $report->withdrawn);
        $this->assertCount(7, $report->withdrawn, 'item outlet pertama tetap ditarik');
        $this->assertTrue(ModeGate::runAs('live', fn () => $menu->isCarried($own->id, $b->id)), 'item pemilik tetap dijual di outlet kedua');
    }

    /** Outlet pertama terisi di kedua mode; LIVE sudah punya outlet kedua (dibuat pengguna), DEMO belum. */
    private function prepareBothModes(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        foreach ($this->wjmRun(['live', 'demo']) as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        }
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));
    }

    public function test_demo_creates_the_second_outlet_with_its_stations_the_same_catalog_and_the_initial_stock(): void
    {
        $this->prepareBothModes();
        $liveBefore = $this->snapshotOf('live', ModeGate::runAs('live', fn () => Outlet::orderBy('id')->firstOrFail()));

        $report = $this->wjmRun(['demo'], path: $this->second())['demo'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['code' => 'WU2', 'name' => 'Warkop Uji 2', 'created' => true], $report->outlet);
        ModeGate::runAs('demo', function () {
            $b = Outlet::where('code', 'WU2')->firstOrFail();
            $this->assertSame(['Bar', 'Dapur'], Station::withoutGlobalScopes()->where('outlet_id', $b->id)->orderBy('display_order')->pluck('name')->all());
            $mine = Product::where('home_outlet_id', $b->id)->get();
            $this->assertSame(['Kopi Uji', 'Keripik Uji', 'Jus Uji'], $mine->sortBy('id')->pluck('name')->values()->all());
            $variants = ProductVariant::whereIn('product_id', $mine->pluck('id'))->get();
            $this->assertCount(5, $variants);
            foreach ($variants as $variant) {
                $this->assertSame(100, (int) VariantOutletStock::where('variant_id', $variant->id)->where('outlet_id', $b->id)->value('current_stock'));
            }
        });
        $this->assertSame($liveBefore, $this->snapshotOf('live', ModeGate::runAs('live', fn () => Outlet::orderBy('id')->firstOrFail())), 'menjalankan DEMO saja tidak menyentuh LIVE');
    }

    public function test_a_demo_failure_leaves_demo_as_it_was_and_keeps_the_live_result(): void
    {
        $this->prepareBothModes();
        $demoA = ModeGate::runAs('demo', fn () => Outlet::orderBy('id')->firstOrFail());
        $demoBefore = $this->snapshotOf('demo', $demoA);
        $demoOutlets = ModeGate::runAs('demo', fn () => Outlet::count());
        $files = count(Storage::disk('public')->allFiles('products'));
        $real = app(OutletMenuService::class);
        $this->partialMock(OutletMenuService::class, function ($mock) use ($real) {
            $mock->shouldReceive('setCarried')->andReturnUsing(function (...$args) use ($real) {
                if (ModeGate::isDemo()) {
                    throw new \RuntimeException('gagal uji');
                }

                return $real->setCarried(...$args);
            });
        });

        $reports = $this->wjmRun(['live', 'demo'], path: $this->second());

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $reports['live']->status, implode(' | ', $reports['live']->problems));
        $this->assertSame(WjmCatalogSeedReport::FAILED, $reports['demo']->status);
        ModeGate::runAs('demo', function () use ($demoOutlets) {
            $this->assertSame($demoOutlets, Outlet::count(), 'outlet DEMO yang sempat dibuat ikut dibatalkan');
            $this->assertSame(0, Product::where('name', 'Keripik Uji')->count());
        });
        $this->assertSame($demoBefore, $this->snapshotOf('demo', $demoA));
        $this->assertSame(3, ModeGate::runAs('live', fn () => Product::where('home_outlet_id', Outlet::where('code', 'WJD')->value('id'))->count()), 'hasil LIVE tetap');
        $this->assertCount($files + 1, Storage::disk('public')->allFiles('products'), 'foto DEMO yang sempat ditulis dihapus; foto LIVE tetap');
    }

    private function prepareLiveTargetOnly(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']));
    }

    public function test_the_seeder_fills_the_second_outlet_in_both_modes(): void
    {
        $this->prepareLiveTargetOnly();
        config(['seeding.wjm2_catalog_path' => $this->second()]);

        $this->seed(Wjm2CatalogSeeder::class);

        $this->assertSecondOutletShape();
    }

    public function test_the_command_fills_the_second_outlet_with_the_same_shape_as_the_seeder(): void
    {
        $this->prepareLiveTargetOnly();

        $this->artisan('app:seed-wjm-catalog', ['--catalog' => $this->second()])->assertExitCode(0);

        $this->assertSecondOutletShape();
    }

    /** research D6 (068): hasil sama baik lewat db:seed (unguarded) maupun perintah Artisan (guarded). */
    private function assertSecondOutletShape(): void
    {
        foreach (['live' => 'WJD', 'demo' => 'WU2'] as $mode => $code) {
            ModeGate::runAs($mode, function () use ($code, $mode) {
                $outlet = Outlet::where('code', $code)->firstOrFail();
                $this->assertSame(['Bar', 'Dapur'], Station::withoutGlobalScopes()->where('outlet_id', $outlet->id)->orderBy('display_order')->pluck('name')->all(), $mode);
                $this->assertSame(3, Product::where('home_outlet_id', $outlet->id)->count(), $mode);
                $this->assertSame(3, Product::where('home_outlet_id', $outlet->id)->whereNotNull('station_id')->count(), "{$mode}: station_id per item");
                $this->assertSame(5, ProductVariant::count(), $mode);
            });
        }
        $this->assertSame(4, Station::withoutGlobalScopes()->count(), 'stasiun tidak boleh jatuh ke outlet lain');
    }

    public function test_the_source_accounting_counts_only_attribute_values_that_came_from_the_export(): void
    {
        $this->prepareLive();

        $report = $this->wjmRun(['live'], path: $this->second())['live'];

        $this->assertContains('nilai atribut 0 = 0 dipakai varian + 0 opsi grup + 0 dikecualikan', $report->sourceAccounting);
        $this->assertContains('kategori 3 = 1 dibuat + 1 sudah ada + 1 dikecualikan', $report->sourceAccounting);
    }
}
