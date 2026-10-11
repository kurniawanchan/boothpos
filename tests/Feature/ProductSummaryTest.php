<?php

namespace Tests\Feature;

use App\Models\ItemAvailabilityMark;
use App\Models\MenuChange;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T029) — GET /products/summary: angka header sama dengan baris di bawahnya (SC-001).
 */
class ProductSummaryTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function item(string $name, array $product = [], float $cost = 1000): Product
    {
        $v = $this->makeVariant(stock: 5, price: 10000, variantOverrides: ['cost_price' => $cost]);
        $v->product->update(array_merge(['name' => $name], $product));

        return $v->product->fresh();
    }

    private function summary(): array
    {
        return $this->getJson('/api/v1/products/summary')->assertOk()->json();
    }

    public function test_active_total_draft_and_discontinued_equal_the_list(): void
    {
        $station = Station::create(['name' => 'Bar', 'display_order' => 1, 'is_active' => true]);
        $this->item('A', ['station_id' => $station->id]);
        $this->item('B', ['station_id' => $station->id]);
        $draft = $this->item('C', ['station_id' => $station->id]);
        $draft->forceFill(['is_active' => false, 'published_at' => null])->saveQuietly();
        $this->item('D', ['station_id' => $station->id])->update(['is_active' => false]);
        $this->item('Ritel', ['is_retail' => true]);                 // ritel tidak dihitung di "Menu item"

        $s = $this->summary();

        $this->assertSame(['count' => 2, 'total' => 4, 'draft' => 1, 'discontinued' => 1], $s['active']);
        foreach (['active' => 2, 'draft' => 1, 'discontinued' => 1] as $status => $n) {
            $this->assertSame($n, $this->getJson("/api/v1/products?tab=menu&status[]={$status}")->json('meta.total'), $status);
        }
    }

    public function test_sold_out_today_counts_distinct_items_with_examples_and_ignores_expired_marks(): void
    {
        $a = $this->item('Es Jeruk');
        $b = $this->item('Susu Kental');
        $c = $this->item('Lama');
        $reason = ReasonCode::where('category', 'sold_out')->first();
        $mk = fn ($p, $exp) => ItemAvailabilityMark::create(['product_id' => $p->id, 'reason_code_id' => $reason->id, 'marked_at' => now(), 'expires_at' => $exp]);
        $mk($a, now()->addHour());
        $mk($a, now()->addHours(2));                                  // dua tanda pada item yang sama → satu item
        $mk($b, now()->addHour());
        $mk($c, now()->subMinute());

        $s = $this->summary()['sold_out_today'];

        $this->assertSame(2, $s['count']);
        $this->assertSame(2, count($s['examples']));
        $this->assertContains('Es Jeruk', array_column($s['examples'], 'product'));
    }

    public function test_quality_reports_the_first_kind_with_items_and_its_examples(): void
    {
        $this->item('Tahu Crispy');
        $this->item('Kentang Goreng');
        $s = $this->summary()['quality'];

        $this->assertSame(['count' => 2, 'examples' => ['Kentang Goreng', 'Tahu Crispy'], 'kind' => 'no_station'], $s);
        $this->assertSame(2, $this->getJson('/api/v1/products?tab=menu&quality=no_station')->json('meta.total'));
    }

    public function test_quality_is_clear_when_nothing_is_missing(): void
    {
        $station = Station::create(['name' => 'Bar', 'display_order' => 1, 'is_active' => true]);
        $this->item('Lengkap', ['station_id' => $station->id]);

        $this->assertSame(['count' => 0, 'examples' => [], 'kind' => null], $this->summary()['quality']);
    }

    public function test_unreleased_is_null_when_releases_are_off_and_counts_changes_when_on(): void
    {
        $s = $this->summary();
        $this->assertFalse($s['release_enabled']);
        $this->assertNull($s['unreleased']);

        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $p = $this->item('Kopi Susu');
        foreach ([MenuChange::PENDING, MenuChange::AWAITING_APPROVAL, MenuChange::RELEASED] as $status) {
            MenuChange::create(['kind' => 'price', 'subject_type' => 'variant', 'subject_id' => $p->variants()->value('id'), 'payload' => ['product_name' => 'Kopi Susu'], 'status' => $status]);
        }

        $s = $this->summary();
        $this->assertTrue($s['release_enabled']);
        $this->assertSame(2, $s['unreleased']['count']);              // yang sudah dirilis tidak dihitung
        $this->assertNull($s['unreleased']['active_version']);
        $this->assertSame('Harga Kopi Susu', $s['unreleased']['summary'][0]);
    }

    public function test_a_user_without_any_menu_can_still_read_the_summary_like_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/products/summary')->assertOk();
    }
}
