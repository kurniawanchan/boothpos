<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MenuChange;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantOutletPrice;
use App\Services\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T048) — harga outlet lewat jalur rilis/persetujuan yang sama dengan harga dasar.
 */
class OutletPriceReleaseTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $p = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $p->variants()->create(['sku' => 'REL00001', 'variant_name' => 'Regular', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 5]);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
    }

    public function test_a_small_change_waits_then_publishes_as_an_outlet_price_with_history(): void
    {
        $r = $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => 21000])->assertOk()->json();
        $this->assertTrue($r['pending']);
        $this->assertFalse($r['requires_approval']);
        $this->assertSame(0, VariantOutletPrice::count());

        app(ReleaseService::class)->publish($this->owner);

        $this->assertSame('21000.00', number_format((float) VariantOutletPrice::where('outlet_id', $this->b->id)->value('price'), 2, '.', ''));
        $this->assertSame(20000.0, (float) $this->variant->fresh()->sell_price, 'base price untouched');
        $history = PriceHistory::where('outlet_id', $this->b->id)->where('source', 'release')->first();
        $this->assertNotNull($history);
        $this->assertNotNull($history->change_id);
    }

    public function test_a_large_difference_waits_for_approval_and_names_the_outlet(): void
    {
        $r = $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => 40000])->assertOk()->json();

        $this->assertTrue($r['requires_approval']);
        $change = MenuChange::findOrFail($r['change_id']);
        $this->assertSame('awaiting_approval', $change->status);
        $this->assertSame($this->b->code, $change->payload['outlet_code']);

        $rows = $this->getJson('/api/v1/menu/changes')->assertOk()->json('data');
        $this->assertSame($this->b->code, collect($rows)->firstWhere('id', $change->id)['outlet_code']);
    }

    public function test_removing_an_override_is_released_as_a_return_to_base(): void
    {
        VariantOutletPrice::create(['variant_id' => $this->variant->id, 'outlet_id' => $this->b->id, 'price' => 21000]);

        $r = $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => null])->assertOk()->json();
        $this->assertTrue($r['pending']);
        app(ReleaseService::class)->publish($this->owner);

        $this->assertSame(0, VariantOutletPrice::count());
    }

    public function test_an_unchanged_price_queues_nothing(): void
    {
        VariantOutletPrice::create(['variant_id' => $this->variant->id, 'outlet_id' => $this->b->id, 'price' => 21000]);

        $r = $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => 21000])->assertOk()->json();

        $this->assertFalse($r['changed']);
        $this->assertSame(0, MenuChange::count());
    }

    public function test_price_history_names_the_outlet_and_hides_outlets_the_caller_cannot_see(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'false', 'type' => 'boolean']);
        $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => 21000])->assertOk();
        $productId = $this->variant->product_id;

        $rows = collect($this->getJson("/api/v1/products/{$productId}/price-history")->assertOk()->json('data'));
        $this->assertSame($this->b->code, $rows->firstWhere('outlet_code', $this->b->code)['outlet_code']);

        $role = \App\Models\Role::create(['name' => 'Pengelola Riwayat '.uniqid(), 'menu_keys' => ['dashboard', 'products'], 'all_outlets' => false]);
        $manager = $this->assignUser(User::factory()->create(['role_id' => $role->id]), [$this->a]);
        $this->actingAs($manager, 'sanctum');
        $seen = collect($this->getJson("/api/v1/products/{$productId}/price-history")->assertOk()->json('data'))->pluck('outlet_code')->filter()->all();
        $this->assertSame([], $seen);
    }
}
