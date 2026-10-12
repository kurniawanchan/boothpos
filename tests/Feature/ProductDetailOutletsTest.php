<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 — halaman item: ketersediaan satu baris per outlet yang terlihat.
 */
class ProductDetailOutletsTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function product(int $outletId): Product
    {
        $p = Product::factory()->create(['home_outlet_id' => $outletId, 'category_id' => Category::factory()->create()->id, 'name' => 'Kopi']);
        $p->variants()->create(['sku' => 'DET00001', 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 5]);

        return $p;
    }

    public function test_availability_has_one_row_per_outlet_with_carried_and_86_state(): void
    {
        [$a, $b, $c] = $this->makeOutlets(3)->all();
        $p = $this->product($a->id);
        app(OutletMenuService::class)->setCarried($p, [$a->id, $b->id]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $reason = (int) ReasonCode::where('category', 'sold_out')->value('id');
        $this->postJson("/api/v1/products/{$p->id}/sold-out", ['reason_code_id' => $reason, 'outlet_ids' => [$b->id]])->assertCreated();

        $rows = collect($this->getJson("/api/v1/products/{$p->id}")->assertOk()->json('availability'));

        $this->assertSame([$a->id => 'available', $b->id => 'sold_out', $c->id => 'not_carried'], $rows->pluck('state', 'outlet_id')->all());
        $this->assertNotNull($rows->firstWhere('outlet_id', $b->id)['expires_at']);
        $this->assertNull($rows->firstWhere('outlet_id', $c->id)['reason']);
    }

    public function test_a_single_outlet_keeps_the_legacy_single_row(): void
    {
        [$a] = $this->makeOutlets(1)->all();
        $p = $this->product($a->id);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $rows = $this->getJson("/api/v1/products/{$p->id}")->assertOk()->json('availability');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['outlet_id']);
    }

    public function test_outlet_prices_list_only_real_overrides_and_only_with_several_outlets(): void
    {
        [$a, $b] = $this->makeOutlets(2)->all();
        $p = $this->product($a->id);
        $variant = $p->variants()->first();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->putJson("/api/v1/variants/{$variant->id}/outlet-prices/{$b->id}", ['price' => 12500])->assertOk();

        $json = $this->getJson("/api/v1/products/{$p->id}")->assertOk()->json('outlet_prices');

        $this->assertSame([$a->id, $b->id], collect($json['outlets'])->pluck('id')->all());
        $this->assertSame([$variant->id => [$b->id => '12500.00']], $json['by_variant']);
    }

    public function test_outlet_prices_are_null_for_a_single_outlet(): void
    {
        [$a] = $this->makeOutlets(1)->all();
        $p = $this->product($a->id);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->assertNull($this->getJson("/api/v1/products/{$p->id}")->assertOk()->json('outlet_prices'));
    }
}
