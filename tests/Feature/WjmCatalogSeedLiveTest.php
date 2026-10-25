<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\VariantOutletStock;
use App\Services\OutletMenuService;
use App\Support\MenuAvailability;
use App\Support\ModeGate;
use App\Support\WjmCatalogSeedReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 068 US1 (FR-001–FR-014, FR-019) — satu jalan mengisi outlet "Warkop WJM 3" di LIVE dengan stasiun, kategori,
 * item, varian, harga, foto, dan grup pilihan; item langsung dapat dipesan, hanya di outlet itu.
 */
class WjmCatalogSeedLiveTest extends TestCase
{
    use RefreshDatabase;
    use WjmCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_an_empty_live_mode_gets_the_outlet_stations_and_the_whole_catalog_ready_to_order(): void
    {
        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['code' => 'WJ3', 'name' => 'Warkop WJM 3', 'created' => true], $report->outlet);

        ModeGate::runAs('live', function () {
            $outlet = Outlet::where('code', 'WJ3')->firstOrFail();
            $this->assertTrue($outlet->is_active);
            $stations = Station::withoutGlobalScopes()->where('outlet_id', $outlet->id)->pluck('id', 'name');
            $this->assertEqualsCanonicalizing(['Bar', 'Dapur'], $stations->keys()->all());

            $categories = Category::orderBy('display_order')->get();
            $this->assertSame(['Kopi Uji', 'Makanan Uji', 'Tambahan Uji'], $categories->pluck('name')->all());
            $this->assertSame([0, 1, 2], $categories->pluck('display_order')->all());
            $this->assertSame(['KU', 'MU', 'TU'], $categories->pluck('code')->all());
            $this->assertSame([$stations['Bar'], $stations['Dapur'], $stations['Dapur']], $categories->pluck('station_id')->all());

            $products = Product::with('variants')->get()->keyBy('name');
            $this->assertCount(7, $products);
            foreach ($products as $product) {
                $this->assertTrue($product->is_active, $product->name);
                $this->assertNotNull($product->published_at, $product->name);
                $this->assertSame($outlet->id, $product->home_outlet_id);
                $this->assertMatchesRegularExpression('/^WJ3-[A-Z0-9]{2}-[A-Z]{3}$/', $product->code_prefix);
            }
            // collect(): only() milik Eloquent\Collection menyaring menurut primary key, bukan kunci larik.
            $roti = collect($products->all())->only(['Roti Bakar Keju', 'Roti Bakar Coklat', 'Roti Bakar Coklat Keju'])->pluck('product_segment');
            $this->assertCount(3, $roti->unique(), 'tiga "Roti Bakar …" harus bersegmen berbeda');

            $variants = ProductVariant::with('product')->get();
            $this->assertCount(11, $variants);
            $this->assertSame(
                ['Kecil Pedas' => 20000.0, 'Kecil Sedang' => 20000.0, 'Besar Pedas' => 25000.0, 'Besar Sedang' => 25000.0],
                $products['Nasi Paket Uji']->variants->sortBy('id')->mapWithKeys(fn ($v) => [$v->variant_name => (float) $v->sell_price])->all(),
            );
            foreach ($variants as $variant) {
                $this->assertFalse((bool) $variant->track_stock, $variant->sku);
                $this->assertSame(0.0, (float) $variant->cost_price);
                $this->assertTrue((bool) $variant->is_active);
                $this->assertTrue(PriceHistory::where('variant_id', $variant->id)->where('source', 'created')->exists(), $variant->sku);
                $this->assertTrue(MenuAvailability::isOrderable($variant, $outlet->id), $variant->sku);
            }

            $kopi = $products['Kopi Uji'];
            $keju = $products['Roti Bakar Keju'];
            $this->assertNotNull($kopi->image_path);
            $this->assertNotSame($kopi->image_path, $keju->image_path);
            Storage::disk('public')->assertExists([$kopi->image_path, $keju->image_path]);
            $this->assertNull($products['Nasi Putih Uji']->image_path);
            $this->assertNull($products['Kit Uji']->image_path);

            $group = ModifierGroup::with(['options', 'products'])->where('name', 'Rasa')->firstOrFail();
            $this->assertSame(ModifierGroup::SINGLE, $group->selection_type);
            $this->assertTrue((bool) $group->is_required);
            $this->assertSame([1, 1], [(int) $group->min_select, (int) $group->max_select]);
            $this->assertSame(['Hazelnut', 'Vanilla'], $group->options->sortBy('display_order')->pluck('name')->values()->all());
            $this->assertSame([0.0, 0.0], $group->options->map(fn ($o) => (float) $o->price_delta)->all());
            $this->assertSame(['Kopi Uji'], $group->products->pluck('name')->all());
        });

        $logs = ActivityLog::where('action', 'catalog_seeded')->get();
        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]->user_id);
        $this->assertSame('Outlet', $logs[0]->entity_type);
        $this->assertSame(Outlet::withoutGlobalScopes()->where('code', 'WJ3')->value('id'), $logs[0]->entity_id);
        $this->assertSame(7, $logs[0]->new_values['counts']['items']['created']);
    }

    public function test_an_initial_stock_tracks_every_new_variant_and_stocks_it_once_at_the_target_outlet(): void
    {
        $path = $this->wjmCatalogPath(fn (array $d) => array_merge($d, ['initial_stock' => 50]));

        $report = $this->wjmRun(['live'], path: $path)['live'];
        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->wjmRun(['live'], path: $path);

        ModeGate::runAs('live', function () {
            $outlet = Outlet::where('code', 'WJ3')->firstOrFail();
            $variants = ProductVariant::all();
            $this->assertCount(11, $variants);
            foreach ($variants as $variant) {
                $this->assertTrue((bool) $variant->track_stock, $variant->sku);
                $this->assertSame(50, (int) $variant->current_stock, $variant->sku);
                $this->assertSame(50, (int) VariantOutletStock::where('variant_id', $variant->id)->where('outlet_id', $outlet->id)->value('current_stock'), $variant->sku);
                // Jalan kedua melewati item yang sudah ada: tetap satu gerakan `initial`, tidak menumpuk.
                $this->assertSame(['initial'], StockMovement::where('variant_id', $variant->id)->pluck('type')->all(), $variant->sku);
                $this->assertTrue(MenuAvailability::isOrderable($variant, $outlet->id), $variant->sku);
            }
        });
    }

    public function test_only_the_target_outlet_sells_the_catalog_and_other_outlets_keep_their_menu(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'true', 'type' => 'boolean']);
        [$wjm, $other, $oldItem] = ModeGate::runAs('live', function () {
            $wjm = Outlet::factory()->create(['code' => 'WJA', 'name' => 'Warkop WJM 3']);
            $other = Outlet::factory()->create(['code' => 'CBL', 'name' => 'Cabang Lain']);
            $item = Product::factory()->create([
                'home_outlet_id' => $other->id,
                'category_id' => Category::factory()->create(['code' => 'ZZ'])->id,
                'code_prefix' => 'CBL-ZZ-AAA',
                'name' => 'Item Lama',
            ]);

            return [$wjm, $other, $item];
        });

        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['code' => 'WJA', 'name' => 'Warkop WJM 3', 'created' => false], $report->outlet);
        $menu = app(OutletMenuService::class);
        ModeGate::runAs('live', function () use ($menu, $wjm, $other, $oldItem) {
            $seeded = Product::where('home_outlet_id', $wjm->id)->get();
            $this->assertCount(7, $seeded);
            foreach ($seeded as $product) {
                $this->assertTrue($menu->isCarried($product->id, $wjm->id), $product->name);
                $this->assertFalse($menu->isCarried($product->id, $other->id), $product->name);
            }
            $this->assertTrue($menu->isCarried($oldItem->id, $other->id));
        });
    }

    public function test_a_single_active_outlet_with_another_name_is_used_without_creating_one(): void
    {
        ModeGate::runAs('live', fn () => Outlet::factory()->create(['code' => 'TKO', 'name' => 'Toko Utama']));

        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame(['code' => 'TKO', 'name' => 'Toko Utama', 'created' => false], $report->outlet);
        $this->assertSame(1, ModeGate::runAs('live', fn () => Outlet::count()));
    }

    public function test_an_ambiguous_or_unknown_target_outlet_is_refused_without_any_change(): void
    {
        ModeGate::runAs('live', function () {
            Outlet::factory()->create(['code' => 'AAA', 'name' => 'Outlet A']);
            Outlet::factory()->create(['code' => 'BBB', 'name' => 'Outlet B']);
        });
        $before = $this->rowCounts();

        $ambiguous = $this->wjmRun(['live'])['live'];
        $this->assertSame(WjmCatalogSeedReport::REFUSED, $ambiguous->status);
        $this->assertStringContainsString('AAA', $ambiguous->problems[0]);
        $this->assertStringContainsString('BBB', $ambiguous->problems[0]);
        $this->assertSame($before, $this->rowCounts());

        $unknown = $this->wjmRun(['live'], outlet: 'XYZ')['live'];
        $this->assertSame(WjmCatalogSeedReport::REFUSED, $unknown->status);
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_a_draft_catalog_is_refused_without_any_change(): void
    {
        $before = $this->rowCounts();
        $draft = $this->wjmCatalogPath(fn (array $d) => array_replace($d, ['approval' => ['status' => 'draft', 'approved_on' => null, 'approved_by_role' => null]]));

        $report = $this->wjmRun(['live'], path: $draft)['live'];

        $this->assertSame(WjmCatalogSeedReport::REFUSED, $report->status);
        $this->assertStringContainsString('DRAF', $report->problems[0]);
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_the_first_live_outlet_is_created_without_a_multi_outlet_licence_even_when_demo_has_outlets(): void
    {
        Setting::updateOrCreate(['key' => 'multi_outlet_enabled'], ['value' => 'false', 'type' => 'boolean']);
        ModeGate::runAs('demo', fn () => Outlet::factory()->create(['code' => 'WJ3', 'name' => 'Warkop WJM 3']));

        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        // Kode outlet unik lintas mode: WJ3 milik DEMO, jadi outlet LIVE mendapat WJM (research D5).
        $this->assertSame(['code' => 'WJM', 'name' => 'Warkop WJM 3', 'created' => true], $report->outlet);
    }

    public function test_the_default_database_seeder_never_seeds_the_wjm_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
        $this->assertStringNotContainsString('WjmCatalogSeeder', (string) file_get_contents(database_path('seeders/DatabaseSeeder.php')));
        $this->assertStringNotContainsString('Wjm2CatalogSeeder', (string) file_get_contents(database_path('seeders/DatabaseSeeder.php')));
    }
}
