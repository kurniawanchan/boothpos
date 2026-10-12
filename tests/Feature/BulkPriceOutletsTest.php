<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantOutletPrice;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 — ubah harga massal dengan cakupan outlet.
 */
class BulkPriceOutletsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private $category;

    private Product $kopi;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->category = Category::factory()->create();
        $this->kopi = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => $this->category->id, 'name' => 'Kopi']);
        $this->kopi->variants()->create(['sku' => 'BLK00001', 'variant_name' => 'Regular', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 5]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function body(array $over = []): array
    {
        return array_merge(['category_ids' => [$this->category->id], 'type' => 'percent', 'value' => 10, 'rounding' => 'none', 'effective_from' => \App\Support\BusinessDay::today()], $over);
    }

    public function test_preview_for_chosen_outlets_starts_from_the_outlets_effective_price(): void
    {
        $variant = $this->kopi->variants()->first();
        VariantOutletPrice::create(['variant_id' => $variant->id, 'outlet_id' => $this->b->id, 'price' => 30000]);

        $items = collect($this->postJson('/api/v1/price-changes/preview', $this->body(['price_scope' => 'outlets', 'outlet_ids' => [$this->a->id, $this->b->id]]))
            ->assertOk()->json('items'))->keyBy('outlet_id');

        $this->assertSame('22000.00', $items[$this->a->id]['new_price']);
        $this->assertSame('33000.00', $items[$this->b->id]['new_price']);
    }

    public function test_apply_writes_overrides_and_leaves_the_base_price_alone(): void
    {
        $variant = $this->kopi->variants()->first();

        $this->postJson('/api/v1/price-changes', $this->body(['price_scope' => 'all_outlets']))->assertCreated();

        $this->assertSame('20000.00', number_format((float) $variant->fresh()->sell_price, 2, '.', ''));
        $this->assertEquals([22000.0, 22000.0], VariantOutletPrice::where('variant_id', $variant->id)->orderBy('outlet_id')->pluck('price')->map(fn ($p) => (float) $p)->all());
    }

    public function test_outlets_that_do_not_carry_the_item_are_skipped(): void
    {
        app(OutletMenuService::class)->setCarried($this->kopi, [$this->a->id]);

        $items = $this->postJson('/api/v1/price-changes/preview', $this->body(['price_scope' => 'all_outlets']))->assertOk()->json('items');

        $this->assertSame([$this->a->id], array_column($items, 'outlet_id'));
    }

    public function test_the_default_scope_is_still_the_base_price(): void
    {
        $variant = $this->kopi->variants()->first();

        $this->postJson('/api/v1/price-changes', $this->body())->assertCreated();

        $this->assertSame(22000.0, (float) $variant->fresh()->sell_price);
        $this->assertSame(0, VariantOutletPrice::count());
    }

    public function test_outlet_scope_needs_outlets_and_a_valid_scope(): void
    {
        $this->postJson('/api/v1/price-changes/preview', $this->body(['price_scope' => 'outlets', 'outlet_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('outlet_ids');
        $this->postJson('/api/v1/price-changes/preview', $this->body(['price_scope' => 'nope']))->assertUnprocessable()->assertJsonValidationErrors('price_scope');

    }

    public function test_an_outlet_outside_the_callers_permitted_set_is_refused(): void
    {
        $role = \App\Models\Role::create([
            'name' => 'Pengelola Harga '.uniqid(), 'menu_keys' => ['dashboard', 'products', 'outlets'],
            'all_outlets' => false, 'permissions' => [\App\Support\PermissionCatalog::PRICE_BULK],
        ]);
        $manager = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $this->actingAs($manager, 'sanctum');

        $this->postJson('/api/v1/price-changes/preview', $this->body(['price_scope' => 'outlets', 'outlet_ids' => [$this->b->id]]))->assertStatus(422);
    }

    public function test_with_releases_on_outlet_prices_become_pending_changes_that_name_the_outlet(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $variant = $this->kopi->variants()->first();

        $result = $this->postJson('/api/v1/price-changes', $this->body(['price_scope' => 'outlets', 'outlet_ids' => [$this->b->id]]))->assertCreated()->json();

        $this->assertSame(0, VariantOutletPrice::count());
        $this->assertSame(1, $result['pending'] + $result['awaiting_approval']);
        $this->assertSame('20000.00', number_format((float) $variant->fresh()->sell_price, 2, '.', ''));
    }
}
