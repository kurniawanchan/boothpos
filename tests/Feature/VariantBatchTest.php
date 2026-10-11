<?php

namespace Tests\Feature;

use App\Models\MenuChange;
use App\Models\PriceHistory;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T059) — PUT /products/{id}/variants (laci Varian & harga).
 */
class VariantBatchTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function saveVariants(int $productId, array $body)
    {
        return $this->putJson("/api/v1/products/{$productId}/variants", $body);
    }

    public function test_it_adds_updates_and_deactivates_in_one_call_with_generated_sku_and_price_history(): void
    {
        $a = $this->makeVariant(stock: 1, price: 8000);
        $p = $a->product;

        $res = $this->saveVariants($p->id, ['variants' => [
            ['id' => $a->id, 'variant_name' => 'Panas', 'sell_price' => 9000, 'is_active' => true],
            ['variant_name' => 'Es', 'sell_price' => 10000, 'sku' => 'IGNORED99'],
        ]])->assertOk()->json();

        $this->assertSame('Panas', $a->fresh()->variant_name);
        $this->assertSame('9000.00', (string) $a->fresh()->sell_price);
        $new = $p->variants()->where('variant_name', 'Es')->firstOrFail();
        $this->assertNotSame('IGNORED99', $new->sku);                          // SKU selalu dibangkitkan server
        $this->assertSame('0.00', (string) $new->cost_price);
        $this->assertSame('edit', PriceHistory::where('variant_id', $a->id)->orderByDesc('id')->value('source'));
        $this->assertSame('created', PriceHistory::where('variant_id', $new->id)->value('source'));
        $this->assertContains('created', array_column($res['applied'], 'action'));

        $this->saveVariants($p->id, ['variants' => [['id' => $a->id, 'variant_name' => 'Panas', 'sell_price' => 9000, 'is_active' => false]]])->assertOk();
        $this->assertFalse((bool) $a->fresh()->is_active);
        $this->assertNotNull($a->fresh());                                      // tidak pernah dihapus
    }

    public function test_validation_names_the_field_and_changes_nothing(): void
    {
        $a = $this->makeVariant(stock: 1, price: 8000);
        $p = $a->product;

        $this->saveVariants($p->id, ['variants' => []])->assertStatus(422);
        $this->saveVariants($p->id, ['variants' => [['id' => $a->id, 'variant_name' => '', 'sell_price' => 1000]]])->assertStatus(422)->assertJsonValidationErrors('variants.0.variant_name');
        $this->saveVariants($p->id, ['variants' => [['id' => $a->id, 'variant_name' => 'X', 'sell_price' => -5]]])->assertStatus(422)->assertJsonValidationErrors('variants.0.sell_price');
        $this->saveVariants($p->id, ['variants' => [['variant_name' => 'X', 'sell_price' => 1000]], 'effective_from' => now()->subDay()->toDateString()])->assertStatus(422);

        $this->assertSame('8000.00', (string) $a->fresh()->sell_price);
        $this->assertSame(1, $p->variants()->count());
    }

    public function test_a_variant_of_another_product_is_refused_and_the_whole_batch_rolls_back(): void
    {
        $a = $this->makeVariant(stock: 1, price: 8000);
        $other = $this->makeVariant(stock: 1, price: 5000);

        $this->saveVariants($a->product_id, ['variants' => [
            ['variant_name' => 'Baru', 'sell_price' => 1000],
            ['id' => $other->id, 'variant_name' => 'Curang', 'sell_price' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('variants.1.id');

        $this->assertSame(1, $a->product->variants()->count());                // varian "Baru" ikut digulung
        $this->assertSame('5000.00', (string) $other->fresh()->sell_price);
    }

    public function test_release_mode_queues_price_changes_and_over_threshold_ones_await_approval(): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => 'true', 'type' => 'boolean']);
        $a = $this->makeVariant(stock: 1, price: 10000);
        $b = $a->product->variants()->create(['sku' => 'ZZ0000000001', 'variant_name' => 'B', 'sell_price' => 10000, 'cost_price' => 0, 'current_stock' => 0]);

        $res = $this->saveVariants($a->product_id, ['variants' => [
            ['id' => $a->id, 'variant_name' => $a->variant_name, 'sell_price' => 10500],
            ['id' => $b->id, 'variant_name' => 'B', 'sell_price' => 15000],
        ]])->assertOk()->json();

        $this->assertSame('10000.00', (string) $a->fresh()->sell_price);
        $this->assertSame(['pending', 'awaiting_approval'], array_column($res['pending'], 'status'));
        $this->assertSame(2, MenuChange::count());
    }

    public function test_the_permission_follows_the_product_policy(): void
    {
        $a = $this->makeVariant(stock: 1, price: 8000);
        $role = Role::factory()->create(['menu_keys' => ['pos']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');

        $this->saveVariants($a->product_id, ['variants' => [['id' => $a->id, 'variant_name' => 'X', 'sell_price' => 1]]])->assertForbidden();
        $this->assertSame('8000.00', (string) $a->fresh()->sell_price);
    }
}
