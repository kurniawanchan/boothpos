<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ItemAvailabilityMark;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T028) — GET /products?tab=… (mode katalog).
 */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function item(string $name, float $price = 10000, array $product = [], array $variant = []): Product
    {
        $v = $this->makeVariant(stock: 5, price: $price, variantOverrides: array_merge(['variant_name' => 'Reguler'], $variant));
        $v->product->update(array_merge(['name' => $name], $product));

        return $v->product->fresh();
    }

    private function list(string $query = 'tab=menu')
    {
        return $this->getJson('/api/v1/products?'.$query)->assertOk();
    }

    public function test_rows_carry_the_fields_the_grid_and_the_table_need(): void
    {
        $station = Station::create(['name' => 'Bar', 'display_order' => 1, 'is_active' => true]);
        $p = $this->item('Kopi Tubruk', 8000, ['short_name' => 'Tubruk', 'station_id' => $station->id], ['cost_price' => 3000]);
        $p->variants()->create(['sku' => 'KOP00002', 'variant_name' => 'Es', 'sell_price' => 10000, 'cost_price' => 3500, 'current_stock' => 1]);

        $row = $this->list()->json('data.0');

        $this->assertSame('Kopi Tubruk', $row['name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(['id' => $station->id, 'name' => 'Bar', 'source' => 'product'], $row['station']);
        $this->assertSame('8000.00', $row['base_price']);
        $this->assertSame(['min' => '8000.00', 'max' => '10000.00'], $row['price_range']);
        $this->assertSame('Es', $row['variants_summary']);               // "Reguler" bukan pilihan bernama
        $this->assertSame(2, $row['variant_count']);
        $this->assertSame([['outlet_id' => null, 'outlet_code' => null, 'state' => 'available', 'reason' => null]], $row['availability']);
        $this->assertSame(37.5, $row['food_cost_pct']);
    }

    public function test_tab_counts_are_independent_of_filters_and_cover_every_type(): void
    {
        $this->item('A');
        $this->item('B', 1000, ['is_retail' => true]);
        \App\Models\Material::create(['code' => 'M1', 'name' => 'Kopi', 'unit' => 'g', 'kind' => 'ingredient']);
        \App\Models\Material::create(['code' => 'M2', 'name' => 'Saus', 'unit' => 'ml', 'kind' => 'semi_finished']);
        \App\Models\Material::create(['code' => 'M3', 'name' => 'Cup', 'unit' => 'pcs', 'kind' => 'packaging']);
        \App\Models\ModifierGroup::create(['name' => 'Gula']);

        $counts = $this->list('tab=menu&q=tidak-ada')->json('counts');

        $this->assertSame(['menu' => 1, 'retail' => 1, 'material' => 1, 'semi_finished' => 1, 'packaging' => 1, 'modifier' => 1], $counts);
    }

    public function test_the_retail_tab_lists_only_retail_items(): void
    {
        $this->item('Menu A');
        $this->item('Ritel B', 1000, ['is_retail' => true]);

        $this->assertSame(['Menu A'], array_column($this->list('tab=menu')->json('data'), 'name'));
        $this->assertSame(['Ritel B'], array_column($this->list('tab=retail')->json('data'), 'name'));
    }

    public function test_search_matches_name_short_name_sku_and_code_prefix(): void
    {
        $a = $this->item('Kopi Susu', 12000, ['short_name' => 'KopSus']);
        $this->item('Teh Tarik');

        $this->assertSame(['Kopi Susu'], array_column($this->list('tab=menu&q=susu')->json('data'), 'name'));
        $this->assertSame(['Kopi Susu'], array_column($this->list('tab=menu&q=KopSus')->json('data'), 'name'));
        $this->assertSame(['Kopi Susu'], array_column($this->list('tab=menu&q='.$a->variants()->value('sku'))->json('data'), 'name'));
        $this->assertSame(['Kopi Susu'], array_column($this->list('tab=menu&q='.$a->code_prefix)->json('data'), 'name'));
        $this->assertSame([], $this->list('tab=menu&q=zzz')->json('data'));
    }

    public function test_status_filter_and_the_derived_status(): void
    {
        $active = $this->item('Aktif');
        $draft = $this->item('Draft');
        $draft->forceFill(['is_active' => false, 'published_at' => null, 'discontinued_at' => null])->saveQuietly();
        $gone = $this->item('Gone');
        $gone->update(['is_active' => false]);

        $this->assertSame(['Aktif'], array_column($this->list('tab=menu&status[]=active')->json('data'), 'name'));
        $this->assertSame(['Draft'], array_column($this->list('tab=menu&status[]=draft')->json('data'), 'name'));
        $this->assertSame(['Gone'], array_column($this->list('tab=menu&status[]=discontinued')->json('data'), 'name'));
        $this->assertEqualsCanonicalizing(['Aktif', 'Gone'], array_column($this->list('tab=menu&status[]=active&status[]=discontinued')->json('data'), 'name'));
        $this->assertSame(3, $this->list()->json('meta.total'));
    }

    public function test_category_filter_includes_child_categories(): void
    {
        $parent = Category::factory()->create(['name' => 'Kopi']);
        $child = Category::factory()->create(['name' => 'Kopi Dingin', 'parent_id' => $parent->id]);
        $other = Category::factory()->create(['name' => 'Nasi']);
        $this->item('Tubruk', 8000, ['category_id' => $parent->id]);
        $this->item('Es Kopi', 15000, ['category_id' => $child->id]);
        $this->item('Nasi Goreng', 20000, ['category_id' => $other->id]);

        $names = array_column($this->list('tab=menu&category_id[]='.$parent->id)->json('data'), 'name');

        $this->assertEqualsCanonicalizing(['Tubruk', 'Es Kopi'], $names);
    }

    public function test_quality_filter_flags_missing_station_price_and_cost(): void
    {
        $station = Station::create(['name' => 'Bar', 'display_order' => 1, 'is_active' => true]);
        $this->item('Lengkap', 10000, ['station_id' => $station->id], ['cost_price' => 4000]);
        $this->item('Tanpa Station', 10000, [], ['cost_price' => 4000]);
        $this->item('Tanpa Biaya', 10000, ['station_id' => $station->id], ['cost_price' => 0]);

        $this->assertSame(['Tanpa Station'], array_column($this->list('tab=menu&quality=no_station')->json('data'), 'name'));
        $this->assertContains('Tanpa Biaya', array_column($this->list('tab=menu&quality=no_cost')->json('data'), 'name'));
        $rows = collect($this->list()->json('data'))->keyBy('name');
        $this->assertSame([], $rows['Lengkap']['quality']);
        $this->assertContains('no_station', $rows['Tanpa Station']['quality']);
    }

    public function test_a_category_station_counts_as_a_station(): void
    {
        $station = Station::create(['name' => 'Dapur', 'display_order' => 1, 'is_active' => true]);
        $cat = Category::factory()->create(['station_id' => $station->id]);
        $this->item('Pakai Station Kategori', 10000, ['category_id' => $cat->id], ['cost_price' => 1000]);

        $row = $this->list('tab=menu&quality=no_station');
        $this->assertSame([], $row->json('data'));
        $this->assertSame('category', $this->list()->json('data.0.station.source'));
    }

    public function test_sorting_and_pagination(): void
    {
        $this->item('Charlie', 30000);
        $this->item('Alpha', 20000);
        $this->item('Bravo', 10000);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column($this->list('tab=menu&sort=name')->json('data'), 'name'));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], array_column($this->list('tab=menu&sort=-name')->json('data'), 'name'));
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], array_column($this->list('tab=menu&sort=price')->json('data'), 'name'));
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], array_column($this->list('tab=menu&sort=-price')->json('data'), 'name'));

        $page = $this->list('tab=menu&per_page=2&page=2');
        $this->assertSame(1, count($page->json('data')));
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $page->json('meta'));
    }

    public function test_an_active_mark_shows_as_sold_out_with_the_reason_and_an_expired_one_does_not(): void
    {
        $a = $this->item('Es Jeruk');
        $b = $this->item('Es Teh');
        $reason = ReasonCode::where('category', 'sold_out')->first();
        $mk = fn ($p, $exp) => ItemAvailabilityMark::create(['product_id' => $p->id, 'reason_code_id' => $reason->id, 'marked_at' => now(), 'expires_at' => $exp]);
        $mk($a, now()->addHour());
        $mk($b, now()->subMinute());

        $rows = collect($this->list()->json('data'))->keyBy('name');

        $this->assertSame('sold_out', $rows['Es Jeruk']['availability'][0]['state']);
        $this->assertSame($reason->label(), $rows['Es Jeruk']['availability'][0]['reason']);
        $this->assertSame('available', $rows['Es Teh']['availability'][0]['state']);
    }

    public function test_the_sold_out_filter_lists_exactly_the_items_the_header_figure_counts(): void
    {
        $a = $this->item('Es Jeruk');
        $this->item('Es Teh');
        $mk = fn ($p, $exp) => ItemAvailabilityMark::create(['product_id' => $p->id, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'), 'marked_at' => now(), 'expires_at' => $exp]);
        $mk($a, now()->addHour());

        $this->assertSame(['Es Jeruk'], array_column($this->list('tab=menu&availability=sold_out')->json('data'), 'name'));
        $this->assertSame(1, $this->getJson('/api/v1/products/summary')->json('sold_out_today.count'));
        $this->getJson('/api/v1/products?tab=menu&availability=other')->assertStatus(422);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_rows(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->item("Item $i");
        }
        $this->list('tab=menu&per_page=50');                       // pemanasan (peran pengguna, lisensi)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->list('tab=menu&per_page=50');
        $few = count(DB::getQueryLog());

        for ($i = 4; $i < 12; $i++) {
            $this->item("Item $i");
        }
        DB::flushQueryLog();
        $this->list('tab=menu&per_page=50');

        $this->assertSame($few, count(DB::getQueryLog()), 'jumlah query harus tetap saat baris bertambah');
    }

    public function test_demo_and_live_never_mix(): void
    {
        $this->item('Live Item');
        ModeGate::runAs(ModeGate::DEMO, fn () => $this->assertSame([], $this->list()->json('data')));
    }

    public function test_invalid_filters_are_422_and_the_legacy_mode_is_unchanged_without_a_tab(): void
    {
        $this->getJson('/api/v1/products?tab=nonsense')->assertStatus(422);
        $this->getJson('/api/v1/products?tab=menu&status[]=weird')->assertStatus(422);
        $this->getJson('/api/v1/products?tab=menu&quality=weird')->assertStatus(422);
        $this->getJson('/api/v1/products?tab=menu&sort=cost')->assertStatus(422);

        $this->item('Lama');
        $legacy = $this->getJson('/api/v1/products?with_variants=1')->assertOk();
        $this->assertArrayHasKey('code_prefix', $legacy->json('data.0'));      // bentuk lama
        $this->assertArrayNotHasKey('counts', $legacy->json());
    }

    public function test_a_cashier_can_list_but_sees_no_food_cost(): void
    {
        $this->item('Kopi', 10000, [], ['cost_price' => 4000]);
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $row = $this->list()->json('data.0');

        $this->assertNull($row['food_cost_pct']);
    }
}
