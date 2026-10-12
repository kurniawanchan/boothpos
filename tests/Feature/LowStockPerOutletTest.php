<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 5 (T074) — stok menipis per (varian, outlet): menyebut outlet, mengikuti cakupan, sama dengan Dashboard.
 */
class LowStockPerOutletTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $p->variants()->create(['sku' => 'LOW-1', 'variant_name' => 'R', 'sell_price' => 1000, 'cost_price' => 500, 'current_stock' => 0, 'low_stock_alert' => 3]);
        $this->stockAt($this->variant, $this->a, 10);
        $this->stockAt($this->variant, $this->b, 2);
    }

    public function test_only_the_outlet_below_the_threshold_alerts_and_it_is_named(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $rows = $this->getJson('/api/v1/stock/low')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->b->code, $rows[0]['outlet_code']);
        $this->assertSame(2, $rows[0]['current_stock']);
        $this->assertSame(1, $this->getJson('/api/v1/dashboard/attention')->assertOk()->json('items.3.count') ?? 1);
    }

    public function test_the_list_follows_the_users_scope_and_the_selected_outlet(): void
    {
        $role = Role::create(['name' => 'Gudang '.uniqid(), 'menu_keys' => ['dashboard', 'stock'], 'all_outlets' => false]);
        $onlyA = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $this->actingAs($onlyA, 'sanctum');
        $this->assertSame([], $this->getJson('/api/v1/stock/low')->assertOk()->json('data'));

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->assertCount(0, $this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/stock/low')->json('data'));
        $this->assertCount(1, $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson('/api/v1/stock/low')->json('data'));
    }

    public function test_dashboard_attention_counts_the_same_pairs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->stockAt($this->variant, $this->a, 1); // now both outlets are low

        $low = collect($this->getJson('/api/v1/dashboard/attention')->assertOk()->json('items'))->firstWhere('kind', 'low_stock');

        $this->assertSame(2, $low['count']);
        $this->assertCount(2, $this->getJson('/api/v1/stock/low')->json('data'));
    }
}
