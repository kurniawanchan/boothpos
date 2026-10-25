<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 070 (US1) — filter Outlet halaman Produk: hitungan tab ikut filter outlet, item dihitung sekali walau dibawa beberapa
 * outlet terpilih, dan permintaan HANYA bisa menyempit di dalam outlet yang diizinkan (Konstitusi IV).
 */
class ProductCatalogOutletFilterTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function product(int $outletId, string $name, bool $retail = false): Product
    {
        $p = Product::factory()->create(['home_outlet_id' => $outletId, 'category_id' => Category::factory()->create()->id, 'name' => $name, 'is_retail' => $retail]);
        $p->variants()->create(['sku' => strtoupper(substr(md5($name), 0, 8)), 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);

        return $p;
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    public function test_tab_counts_follow_the_outlet_filter_but_other_kinds_do_not(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->product($a->id, 'Kopi');
        $teh = $this->product($a->id, 'Teh');
        $snack = $this->product($a->id, 'Keripik', retail: true);
        app(OutletMenuService::class)->setCarried($teh, [$a->id]);
        app(OutletMenuService::class)->setCarried($snack, [$a->id]);
        Material::factory()->create(['kind' => 'ingredient']);
        $this->owner();

        $all = $this->getJson('/api/v1/products?tab=menu')->assertOk()->json('counts');
        $atB = $this->getJson("/api/v1/products?tab=menu&outlet_id[]={$b->id}")->assertOk()->json('counts');

        $this->assertSame(2, $all['menu']);
        $this->assertSame(1, $all['retail']);
        $this->assertSame(1, $atB['menu'], 'hanya Kopi dibawa outlet B');
        $this->assertSame(0, $atB['retail']);
        $this->assertSame($all['material'], $atB['material'], 'bahan tidak ikut filter outlet item');
        $this->assertSame($all['modifier'], $atB['modifier']);
    }

    public function test_an_item_carried_by_both_chosen_outlets_is_counted_once(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->product($a->id, 'Kopi');
        $this->owner();

        $json = $this->getJson("/api/v1/products?tab=menu&outlet_id[]={$a->id}&outlet_id[]={$b->id}")->assertOk()->json();

        $this->assertSame(1, $json['meta']['total']);
        $this->assertCount(1, $json['data']);
        $this->assertSame(1, $json['counts']['menu']);
    }

    public function test_outlets_outside_the_users_assignment_are_cut_off(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->product($a->id, 'Kopi');
        $teh = $this->product($a->id, 'Teh');
        app(OutletMenuService::class)->setCarried($teh, [$a->id]);
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$b]);
        $this->actingAs($user, 'sanctum');

        $asked = $this->getJson("/api/v1/products?tab=menu&outlet_id[]={$a->id}")->assertOk()->json();
        $this->assertSame(0, $asked['meta']['total'], 'outlet A tidak boleh dilihat pengguna ini');
        $this->assertSame(0, $asked['counts']['menu']);

        $both = $this->getJson("/api/v1/products?tab=menu&outlet_id[]={$a->id}&outlet_id[]={$b->id}")->assertOk()->json();
        $this->assertSame(['Kopi'], collect($both['data'])->pluck('name')->all(), 'hanya yang dibawa outlet B yang diizinkan');
    }

    public function test_without_the_header_a_two_outlet_filter_works_and_with_it_the_header_still_narrows(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $this->product($a->id, 'Kopi');
        $teh = $this->product($a->id, 'Teh');
        app(OutletMenuService::class)->setCarried($teh, [$a->id]);
        $this->owner();

        $free = $this->getJson("/api/v1/products?tab=menu&outlet_id[]={$a->id}&outlet_id[]={$b->id}")->assertOk()->json();
        $this->assertSame(2, $free['meta']['total']);

        $narrowed = $this->withHeader('X-Outlet-Id', (string) $b->id)->getJson("/api/v1/products?tab=menu&outlet_id[]={$a->id}&outlet_id[]={$b->id}")->assertOk()->json();
        $this->assertSame(['Kopi'], collect($narrowed['data'])->pluck('name')->all(), 'header menyempitkan ke outlet B');
        $this->assertSame(1, $narrowed['counts']['menu']);
    }
}
