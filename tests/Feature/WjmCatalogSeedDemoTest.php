<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Station;
use App\Models\User;
use App\Services\OutletMenuService;
use App\Services\PriceService;
use App\Support\ModeGate;
use App\Support\WjmCatalogSeedReport;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\WjmCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RestaurantFixtures;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 068 US2 (FR-002, FR-004, FR-016, FR-017, research D6) — DEMO memakai menu WJM asli: menu contoh hanya ditarik
 * dari outlet tujuan (tidak dihapus), outlet lain tidak tersentuh, dan salinan DEMO terpisah dari LIVE.
 */
class WjmCatalogSeedDemoTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use WjmCatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_demo_uses_the_existing_wj3_outlet_and_withdraws_placeholder_items_without_deleting_them(): void
    {
        $this->placeholderDemoMenu();
        [$wj3, $placeholderIds, $orderLineId] = ModeGate::runAs('demo', function () {
            $wj3 = Outlet::where('code', 'WJ3')->firstOrFail();
            $ids = Product::where('home_outlet_id', $wj3->id)->pluck('id')->all();
            $cashier = User::withoutGlobalScopes()->where('username', 'kasir01')->firstOrFail();
            $line = $this->addLineTo($this->makeOpenOrder($this->makeShift($cashier), $cashier), ProductVariant::whereIn('product_id', $ids)->firstOrFail());

            return [$wj3, $ids, $line->id];
        });
        $this->assertCount(32, $placeholderIds);

        $report = $this->wjmRun(['demo'])['demo'];

        $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        $this->assertSame(['code' => 'WJ3', 'name' => 'Warkop WJM 3', 'created' => false], $report->outlet);
        $this->assertCount(32, $report->withdrawn);

        $menu = app(OutletMenuService::class);
        ModeGate::runAs('demo', function () use ($menu, $wj3, $placeholderIds, $orderLineId) {
            $this->assertSame(1, Outlet::count(), 'tidak ada outlet baru di DEMO');
            foreach ($placeholderIds as $id) {
                $this->assertFalse($menu->isCarried($id, $wj3->id));
            }
            $this->assertSame(32, Product::whereIn('id', $placeholderIds)->count(), 'item contoh hanya ditarik, tidak dihapus');

            $line = OrderItem::findOrFail($orderLineId);
            $this->assertNotNull(ProductVariant::find($line->variant_id), 'baris order demo lama tetap terbaca');

            $seeded = Product::where('home_outlet_id', $wj3->id)->whereNotIn('id', $placeholderIds)->get();
            $this->assertCount(7, $seeded);
            foreach ($seeded as $product) {
                $this->assertTrue($menu->isCarried($product->id, $wj3->id), $product->name);
            }
        });
    }

    public function test_other_demo_outlets_keep_their_menu_and_do_not_get_wjm_items(): void
    {
        $this->placeholderDemoMenu();
        $menu = app(OutletMenuService::class);
        [$other, $carriedBefore] = ModeGate::runAs('demo', function () use ($menu) {
            $other = Outlet::factory()->create(['code' => 'OLN', 'name' => 'Outlet Lain']);
            Product::factory()->create([
                'home_outlet_id' => $other->id,
                'category_id' => Category::factory()->create(['code' => 'ZZ'])->id,
                'code_prefix' => 'OLN-ZZ-AAA',
                'name' => 'Barang Lain',
            ]);

            return [$other, Product::pluck('id')->filter(fn ($id) => $menu->isCarried($id, $other->id))->values()->all()];
        });

        $this->wjmRun(['demo']);

        ModeGate::runAs('demo', function () use ($menu, $other, $carriedBefore) {
            foreach ($carriedBefore as $id) {
                $this->assertTrue($menu->isCarried($id, $other->id), "produk {$id} masih dijual di Outlet Lain");
            }
            $wj3 = Outlet::where('code', 'WJ3')->firstOrFail();
            $wjm = Product::where('home_outlet_id', $wj3->id)->whereIn('name', ['Kopi Uji', 'Nasi Paket Uji', 'Kit Uji'])->get();
            $this->assertCount(3, $wjm);
            foreach ($wjm as $product) {
                $this->assertFalse($menu->isCarried($product->id, $other->id), $product->name);
            }
        });
    }

    public function test_a_same_named_choice_group_with_other_options_is_left_alone_and_a_distinct_group_is_created(): void
    {
        $this->placeholderDemoMenu();

        $report = $this->wjmRun(['demo'])['demo'];

        $this->assertSame(['Rasa → Rasa (WJM)'], $report->renamedGroups);
        ModeGate::runAs('demo', function () {
            $placeholder = ModifierGroup::with('options')->where('name', 'Rasa')->firstOrFail();
            $this->assertEqualsCanonicalizing(['Cokelat', 'Keju', 'Srikaya', 'Kacang'], $placeholder->options->pluck('name')->all());

            $wjm = ModifierGroup::with(['options', 'products'])->where('name', 'Rasa (WJM)')->firstOrFail();
            $this->assertEqualsCanonicalizing(['Hazelnut', 'Vanilla'], $wjm->options->pluck('name')->all());
            $this->assertSame(['Kopi Uji'], $wjm->products->pluck('name')->all());
        });
    }

    public function test_both_modes_process_live_first_and_never_share_codes(): void
    {
        $reports = $this->wjmRun(['demo', 'live']);

        $this->assertSame(['live', 'demo'], array_keys($reports));
        foreach ($reports as $report) {
            $this->assertSame(WjmCatalogSeedReport::APPLIED, $report->status, implode(' | ', $report->problems));
        }
        $this->assertSame(['KU', 'MU', 'TU'], ModeGate::runAs('live', fn () => Category::orderBy('display_order')->pluck('code')->all()));
        $this->assertSame(['K1', 'M1', 'T1'], ModeGate::runAs('demo', fn () => Category::orderBy('display_order')->pluck('code')->all()));

        $prefixes = Product::withoutGlobalScopes()->pluck('code_prefix');
        $skus = ProductVariant::withoutGlobalScopes()->pluck('sku');
        $this->assertCount(14, $prefixes);
        $this->assertCount(14, $prefixes->unique());
        $this->assertCount(22, $skus->unique());
    }

    public function test_changing_a_demo_price_does_not_touch_the_live_copy(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->wjmRun(['live', 'demo']);
        $owner = User::withoutGlobalScopes()->where('username', 'owner')->firstOrFail();
        $kopiHot = fn () => ProductVariant::whereHas('product', fn ($q) => $q->where('name', 'Kopi Uji'))->where('variant_name', 'Hot')->firstOrFail();

        ModeGate::runAs('demo', function () use ($owner, $kopiHot) {
            $variant = $kopiHot();
            app(PriceService::class)->apply($variant, (float) $variant->sell_price, 12000, $owner, 'edit');
        });

        $this->assertSame(12000.0, (float) ModeGate::runAs('demo', $kopiHot)->sell_price);
        $this->assertSame(10000.0, (float) ModeGate::runAs('live', $kopiHot)->sell_price);
    }

    public function test_a_live_run_never_withdraws_the_owners_items_at_the_target_outlet(): void
    {
        [$outlet, $own] = ModeGate::runAs('live', function () {
            $outlet = Outlet::factory()->create(['code' => 'WJA', 'name' => 'Warkop WJM 3']);
            $own = Product::factory()->create([
                'home_outlet_id' => $outlet->id,
                'category_id' => Category::factory()->create(['code' => 'ZZ'])->id,
                'code_prefix' => 'WJA-ZZ-AAA',
                'name' => 'Menu Pemilik',
            ]);

            return [$outlet, $own];
        });

        $report = $this->wjmRun(['live'])['live'];

        $this->assertSame([], $report->withdrawn);
        $this->assertTrue(ModeGate::runAs('live', fn () => app(OutletMenuService::class)->isCarried($own->id, $outlet->id)));
    }

    public function test_the_seeder_fills_both_modes_with_stations_on_the_target_outlet(): void
    {
        config(['seeding.wjm_catalog_path' => $this->wjmCatalogPath()]);

        $this->seed(WjmCatalogSeeder::class);

        $this->assertSeededShape();
    }

    public function test_the_command_fills_both_modes_with_the_same_shape_as_the_seeder(): void
    {
        $this->artisan('app:seed-wjm-catalog', ['--catalog' => $this->wjmCatalogPath()])->assertExitCode(0);

        $this->assertSeededShape();
    }

    /** research D6: hasil sama baik lewat db:seed (unguarded) maupun perintah Artisan (guarded). */
    private function assertSeededShape(): void
    {
        foreach (['live' => 'WJ3', 'demo' => 'WJM'] as $mode => $code) {
            ModeGate::runAs($mode, function () use ($code) {
                $outlet = Outlet::where('code', $code)->firstOrFail();
                $this->assertSame(['Bar', 'Dapur'], Station::withoutGlobalScopes()->where('outlet_id', $outlet->id)->orderBy('display_order')->pluck('name')->all());
                $this->assertSame(7, Product::where('home_outlet_id', $outlet->id)->count());
                $this->assertSame(11, ProductVariant::count());
                $this->assertSame(3, Category::count());
            });
        }
        $this->assertSame(4, Station::withoutGlobalScopes()->count(), 'stasiun tidak boleh jatuh ke outlet lain');
    }
}
