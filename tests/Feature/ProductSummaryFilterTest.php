<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ItemAvailabilityMark;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 070 (US2) — GET /products/summary dihitung dari himpunan item yang SAMA dengan daftar: outlet, kategori, status, dan
 * pencarian ikut; `quality` dan `availability` (yang dipasang oleh klik pada kartu itu sendiri) tidak ikut (D3).
 */
class ProductSummaryFilterTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    /** @param list<int>|null $carriedAt outlet pembawa (null = semua) */
    private function item(string $name, ?array $carriedAt = null, array $attrs = [], int $price = 10000, ?int $categoryId = null): Product
    {
        $p = Product::factory()->create($attrs + ['home_outlet_id' => $this->a->id, 'category_id' => $categoryId ?? Category::factory()->create()->id, 'name' => $name]);
        $p->variants()->create(['sku' => strtoupper(substr(md5($name), 0, 8)), 'variant_name' => 'Standard', 'sell_price' => $price, 'cost_price' => 4000, 'current_stock' => 5]);
        if ($carriedAt !== null) {
            app(OutletMenuService::class)->setCarried($p, $carriedAt);
        }

        return $p;
    }

    private function mark(Product $p, ?int $outletId = null): void
    {
        ItemAvailabilityMark::create([
            'product_id' => $p->id, 'outlet_id' => $outletId, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'),
            'marked_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function summary(string $query = ''): array
    {
        return $this->getJson('/api/v1/products/summary'.($query === '' ? '' : "?{$query}"))->assertOk()->json();
    }

    public function test_the_active_figure_counts_only_the_items_of_the_chosen_outlet(): void
    {
        $this->item('Kopi');
        $this->item('Teh', [$this->a->id]);
        $draft = $this->item('Roti', [$this->b->id]);
        $draft->forceFill(['published_at' => null, 'discontinued_at' => null])->saveQuietly();
        $gone = $this->item('Es', [$this->b->id]);
        $gone->forceFill(['discontinued_at' => now()])->saveQuietly();

        $all = $this->summary()['active'];
        $atA = $this->summary("outlet_id[]={$this->a->id}")['active'];
        $atB = $this->summary("outlet_id[]={$this->b->id}")['active'];

        $this->assertSame(['count' => 2, 'total' => 4, 'draft' => 1, 'discontinued' => 1], $all);
        $this->assertSame(['count' => 2, 'total' => 2, 'draft' => 0, 'discontinued' => 0], $atA);
        $this->assertSame(['count' => 1, 'total' => 3, 'draft' => 1, 'discontinued' => 1], $atB);
    }

    public function test_a_sold_out_mark_counts_only_where_it_applies(): void
    {
        $kopi = $this->item('Kopi');
        $teh = $this->item('Teh', [$this->b->id]);
        $global = $this->item('Es Jeruk');
        $this->mark($kopi, $this->b->id);   // hanya outlet B
        $this->mark($teh, $this->b->id);    // Teh tidak dibawa outlet A
        $this->mark($global, null);         // global: berlaku di semua outlet

        $this->assertSame(3, $this->summary()['sold_out_today']['count']);
        $this->assertSame(1, $this->summary("outlet_id[]={$this->a->id}")['sold_out_today']['count'], 'hanya tanda global; Kopi di A tersedia dan Teh tak dibawa A');
        $this->assertSame(3, $this->summary("outlet_id[]={$this->b->id}")['sold_out_today']['count']);
    }

    public function test_the_quality_figure_ignores_items_outside_the_chosen_outlet(): void
    {
        $this->item('Kopi', [$this->a->id]);
        $this->item('Teh', [$this->a->id]);
        $this->item('Roti', [$this->b->id]);

        $this->assertSame(3, $this->summary()['quality']['count']);
        $this->assertSame(2, $this->summary("outlet_id[]={$this->a->id}")['quality']['count']);
        $b = $this->summary("outlet_id[]={$this->b->id}")['quality'];
        $this->assertSame(1, $b['count']);
        $this->assertSame(['Roti'], $b['examples']);
    }

    public function test_category_status_and_search_narrow_the_same_set(): void
    {
        $cat = Category::factory()->create();
        $this->item('Kopi Susu', categoryId: $cat->id);
        $this->item('Kopi Hitam');
        $draft = $this->item('Roti');
        $draft->forceFill(['published_at' => null, 'discontinued_at' => null])->saveQuietly();

        $this->assertSame(1, $this->summary("category_id[]={$cat->id}")['active']['total']);
        $this->assertSame(2, $this->summary('q=Kopi')['active']['total']);
        $byStatus = $this->summary('status[]=draft')['active'];
        $this->assertSame(1, $byStatus['total']);
        $this->assertSame(0, $byStatus['count'], 'daftar draf tidak punya item aktif');
    }

    public function test_the_figures_match_the_list_they_sit_above(): void
    {
        $this->item('Kopi');
        $this->item('Teh', [$this->a->id]);
        $this->item('Roti', [$this->b->id]);

        foreach ([$this->a, $this->b] as $o) {
            $total = $this->getJson("/api/v1/products?tab=menu&outlet_id[]={$o->id}")->json('meta.total');
            $this->assertSame($total, $this->summary("outlet_id[]={$o->id}")['active']['total']);
        }
    }

    public function test_quality_and_availability_filters_are_ignored_by_the_figures(): void
    {
        $kopi = $this->item('Kopi');
        $this->item('Teh');
        $this->mark($kopi);

        $plain = $this->summary();
        $this->assertSame($plain['active'], $this->summary('availability=sold_out')['active']);
        $this->assertSame($plain['quality'], $this->summary('quality=no_station')['quality']);
        $this->assertSame($plain['sold_out_today']['count'], $this->summary('availability=sold_out&quality=no_price')['sold_out_today']['count']);
    }

    public function test_without_filters_the_response_keeps_its_shape_and_totals(): void
    {
        $this->item('Kopi');

        $json = $this->summary();

        foreach (['computed_at', 'release_enabled', 'active', 'sold_out_today', 'unreleased', 'quality'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertSame(1, $json['active']['total']);
    }

    public function test_a_filter_outlet_the_user_may_not_see_yields_an_empty_set(): void
    {
        $this->item('Kopi');
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$this->b]);
        $this->actingAs($user, 'sanctum');

        $this->assertSame(0, $this->summary("outlet_id[]={$this->a->id}")['active']['total']);
    }

    public function test_invalid_filter_values_are_refused(): void
    {
        $this->getJson('/api/v1/products/summary?status[]=bogus')->assertStatus(422);
        $this->getJson('/api/v1/products/summary?outlet_id[]=abc')->assertStatus(422);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_items(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->item("Item $i");
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->summary("outlet_id[]={$this->a->id}&q=Item");
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // pemanasan
        $few = $count();
        for ($i = 3; $i < 10; $i++) {
            $this->item("Item $i");
        }

        $this->assertSame($few, $count());
    }
}
