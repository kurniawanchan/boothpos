<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantOutletPrice;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T048, lapisan data) — harga khusus outlet lewat PriceService (satu-satunya penulis).
 */
class VariantOutletPricesTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private ProductVariant $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $product = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $product->variants()->create(['sku' => 'VOP000001', 'variant_name' => 'Standard', 'sell_price' => 20000, 'cost_price' => 8000, 'current_stock' => 10]);
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function service(): PriceService
    {
        return app(PriceService::class);
    }

    public function test_the_price_follows_the_base_until_an_outlet_price_is_set(): void
    {
        $this->assertSame(20000.0, $this->service()->priceFor($this->variant, $this->a->id));
        $this->assertSame(20000.0, $this->service()->priceFor($this->variant, null));

        $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);

        $this->assertSame(25000.0, $this->service()->priceFor($this->variant, $this->b->id));
        $this->assertSame(20000.0, $this->service()->priceFor($this->variant, $this->a->id), 'other outlets keep the base price');
        $this->assertSame(20000.0, (float) $this->variant->fresh()->sell_price, 'the base price is untouched');
    }

    public function test_setting_writes_one_outlet_history_row_and_an_audit_entry(): void
    {
        $result = $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner, 'edit', 'musim hujan');

        $this->assertTrue($result['changed']);
        $history = PriceHistory::where('variant_id', $this->variant->id)->where('outlet_id', $this->b->id)->first();
        $this->assertSame(20000.0, (float) $history->old_price, 'first override: the old effective price is the base');
        $this->assertSame(25000.0, (float) $history->new_price);
        $this->assertSame($this->owner->id, $history->user_id);
        $this->assertNotNull(ActivityLog::where('action', 'outlet_price_changed')->where('entity_id', $this->variant->id)->first());
    }

    public function test_changing_to_the_same_price_is_a_no_op(): void
    {
        $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);
        $count = PriceHistory::count();

        $result = $this->service()->setOutletPrice($this->variant, $this->b->id, '25000.00', $this->owner);

        $this->assertFalse($result['changed']);
        $this->assertSame($count, PriceHistory::count());
    }

    public function test_null_removes_the_override_and_the_outlet_follows_the_base_again(): void
    {
        $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);

        $this->service()->setOutletPrice($this->variant, $this->b->id, null, $this->owner);

        $this->assertSame(0, VariantOutletPrice::count());
        $this->assertSame(20000.0, $this->service()->priceFor($this->variant, $this->b->id));
        $last = PriceHistory::where('outlet_id', $this->b->id)->latest('id')->first();
        $this->assertSame(25000.0, (float) $last->old_price);
        $this->assertSame(20000.0, (float) $last->new_price);
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->setOutletPrice($this->variant, $this->b->id, -1, $this->owner);
    }

    public function test_the_database_also_refuses_a_negative_price(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        VariantOutletPrice::create(['variant_id' => $this->variant->id, 'outlet_id' => $this->b->id, 'price' => -5]);
    }

    public function test_with_releases_on_an_outlet_price_waits_as_a_menu_change_and_is_not_written(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean', 'group' => 'menu']);

        $r = $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);

        $this->assertTrue($r['pending']);
        $this->assertSame(0, VariantOutletPrice::count());
        $this->assertSame($this->b->id, $r['change']->payload['outlet_id']);
    }

    // ---- API ---------------------------------------------------------------

    public function test_the_api_lists_base_and_outlet_prices_and_updates_one_outlet(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => '25000'])
            ->assertOk()->assertJsonPath('effective_price', '25000.00')->assertJsonPath('changed', true);

        $list = $this->getJson("/api/v1/variants/{$this->variant->id}/outlet-prices")->assertOk()->json();
        $this->assertSame('20000.00', $list['base_price']);
        $byId = collect($list['outlets'])->keyBy('id');
        $this->assertSame('25000.00', $byId[$this->b->id]['price']);
        $this->assertTrue($byId[$this->a->id]['follows_base']);

        $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => null])
            ->assertOk()->assertJsonPath('effective_price', '20000.00');
    }

    public function test_the_api_refuses_negative_prices_and_unpermitted_outlets(): void
    {
        $role = \App\Models\Role::create(['name' => 'Pengelola Harga', 'menu_keys' => ['dashboard', 'products'], 'all_outlets' => false]);
        $manager = User::factory()->create(['role_id' => $role->id]);
        $this->assignUser($manager, [$this->a]);
        $this->actingAs($manager, 'sanctum');

        $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->b->id}", ['price' => 1000])
            ->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');
        $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->a->id}", ['price' => -1])->assertStatus(422);

        $ids = collect($this->getJson("/api/v1/variants/{$this->variant->id}/outlet-prices")->json('outlets'))->pluck('id')->all();
        $this->assertSame([$this->a->id], $ids, 'a manager only sees the outlets they can see');
    }

    public function test_a_cashier_cannot_use_the_endpoint(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->putJson("/api/v1/variants/{$this->variant->id}/outlet-prices/{$this->a->id}", ['price' => 1000])->assertForbidden();
    }

    // ---- orders use the outlet price ----------------------------------------

    public function test_a_sale_is_charged_the_outlet_price_and_the_base_price_is_untouched(): void
    {
        $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);
        $cashier = User::factory()->create(['role' => 'cashier']);

        $atA = $this->orderAt($this->shiftAt($this->a, $cashier), $this->variant, 2, ['payments' => [['method' => 'cash', 'amount' => 40000]]]);
        $atB = $this->orderAt($this->shiftAt($this->b, User::factory()->create(['role' => 'cashier'])), $this->variant->fresh(), 2, ['payments' => [['method' => 'cash', 'amount' => 50000]]]);

        $this->assertSame('40000.00', $atA->fresh()->total_amount);
        $this->assertSame('50000.00', $atB->fresh()->total_amount);
        $this->assertSame('25000.00', $atB->items()->first()->sell_price, 'the line snapshots the outlet price');
        $this->assertSame('20000.00', $this->variant->fresh()->sell_price, 'the base price never changes by selling');
    }

    public function test_a_tab_line_uses_the_outlet_price(): void
    {
        $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);
        $cashier = User::factory()->create(['role' => 'cashier']);
        $shift = $this->shiftAt($this->b, $cashier);
        $this->stockAt($this->variant, $this->b, 5); // stok per outlet: tab di B menjual dari saldo B
        $this->actingAs($cashier, 'sanctum');

        $tab = $this->postJson('/api/v1/tabs', ['session_id' => $shift->id])->assertCreated()->json();
        $line = $this->postJson("/api/v1/tabs/{$tab['id']}/items", ['variant_id' => $this->variant->id, 'qty' => 1])->assertCreated()->json();

        $this->assertSame('25000.00', $line['total_amount'] ?? $line['total'] ?? $line['order']['total_amount'] ?? $line['items'][0]['line_total']);
        $this->assertSame('20000.00', $this->variant->fresh()->sell_price);
    }

    public function test_variants_expose_outlet_price_only_for_a_single_effective_outlet(): void
    {
        $this->service()->setOutletPrice($this->variant, $this->b->id, 25000, $this->owner);
        $this->actingAs($this->owner, 'sanctum');
        $price = fn ($json) => collect($json['data'])->flatMap(fn ($p) => $p['variants'])->firstWhere('id', $this->variant->id)['outlet_price'];

        $atB = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson('/api/v1/products?with_variants=1')->assertOk()->json();
        $atA = $this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/products?with_variants=1')->assertOk()->json();
        $all = $this->getJson('/api/v1/products?with_variants=1')->assertOk()->json();

        $this->assertSame('25000.00', $price($atB));
        $this->assertNull($price($atA));
        $this->assertNull($price($all), 'two outlets in effect: no single outlet price');

        // Setelah harga diubah di proses yang sama, buku harga tidak basi.
        $this->service()->setOutletPrice($this->variant, $this->b->id, 26000, $this->owner);
        $again = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson('/api/v1/products?with_variants=1')->json();
        $this->assertSame('26000.00', $price($again));
    }
}
