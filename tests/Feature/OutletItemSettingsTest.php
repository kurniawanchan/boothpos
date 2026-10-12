<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\OutletItemSetting;
use App\Models\Product;
use App\Services\OutletMenuService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T047, lapisan data) — relasi outlet × produk (`outlet_item_settings`).
 */
class OutletItemSettingsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function product(int $homeOutletId): Product
    {
        return Product::factory()->create(['home_outlet_id' => $homeOutletId, 'category_id' => Category::factory()->create()->id]);
    }

    public function test_creating_a_product_materialises_a_carried_row_for_every_outlet(): void
    {
        $outlets = $this->makeOutlets(3);
        $product = $this->product($outlets[0]->id);

        $rows = OutletItemSetting::where('product_id', $product->id)->orderBy('outlet_id')->get();

        $this->assertSame($outlets->pluck('id')->all(), $rows->pluck('outlet_id')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->is_carried === true));
    }

    public function test_creating_an_outlet_materialises_rows_for_the_existing_products(): void
    {
        $first = $this->makeOutlets(1)[0];
        $products = [$this->product($first->id), $this->product($first->id)];

        $second = \App\Models\Outlet::factory()->create();

        foreach ($products as $product) {
            $this->assertTrue(OutletItemSetting::where('product_id', $product->id)->where('outlet_id', $second->id)->exists());
        }
        $this->assertSame(4, OutletItemSetting::count());
    }

    public function test_the_pair_is_unique_and_materialising_twice_changes_nothing(): void
    {
        $outlets = $this->makeOutlets(2);
        $product = $this->product($outlets[0]->id);

        app(OutletMenuService::class)->materializeForProduct($product);
        $this->assertSame(2, OutletItemSetting::where('product_id', $product->id)->count());

        $this->expectException(QueryException::class);
        OutletItemSetting::create(['outlet_id' => $outlets[0]->id, 'product_id' => $product->id, 'is_carried' => true]);
    }

    public function test_rows_follow_the_data_mode_boundary(): void
    {
        $live = $this->makeOutlets(1)[0];
        $demoProduct = ModeGate::runAs('demo', fn () => $this->product($live->id));

        // Produk DEMO tidak mendapat baris untuk outlet LIVE (dan sebaliknya).
        $this->assertSame(0, OutletItemSetting::withoutGlobalScopes()->where('product_id', $demoProduct->id)->count());
    }

    public function test_set_carried_marks_the_chosen_outlets_and_leaves_the_others_off(): void
    {
        $outlets = $this->makeOutlets(3);
        $product = $this->product($outlets[0]->id);
        $service = app(OutletMenuService::class);

        $service->setCarried($product, [$outlets[0]->id, $outlets[2]->id]);

        $this->assertTrue($service->isCarried($product->id, $outlets[0]->id));
        $this->assertFalse($service->isCarried($product->id, $outlets[1]->id));
        $this->assertSame([$outlets[0]->id, $outlets[2]->id], $service->carriedOutletIds($product));

        $service->setCarried($product, []);
        $this->assertSame([], $service->carriedOutletIds($product));
    }

    public function test_a_pair_without_a_row_counts_as_carried(): void
    {
        $outlet = $this->makeOutlets(1)[0];
        $product = $this->product($outlet->id);
        OutletItemSetting::query()->delete();

        $this->assertTrue(app(OutletMenuService::class)->isCarried($product->id, $outlet->id));
    }
}
