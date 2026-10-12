<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReasonCode;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OutletMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 045 Stage 3 (T049) — 86 per outlet dan "tidak dibawa outlet ini" pada saat order.
 */
class OutletAvailabilityTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private Product $product;

    private ProductVariant $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->product = Product::factory()->create(['home_outlet_id' => $this->a->id, 'category_id' => Category::factory()->create()->id]);
        $this->variant = $this->product->variants()->create(['sku' => 'AVL000001', 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function reason(): int
    {
        return (int) ReasonCode::where('category', 'sold_out')->orderBy('sort')->value('id');
    }

    private function sellAt($outlet): ?string
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $shift = $this->shiftAt($outlet, $cashier);
        try {
            $this->orderAt($shift, $this->variant, 1);

            return null;
        } catch (ValidationException $e) {
            return $e->errors()['items'][0] ?? 'refused';
        }
    }

    public function test_marking_sold_out_at_one_outlet_blocks_only_that_outlet(): void
    {
        $this->postJson("/api/v1/products/{$this->product->id}/sold-out", ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->a->id]])
            ->assertCreated()->assertJsonPath('outlet_ids', [$this->a->id]);

        $this->assertNotNull($this->sellAt($this->a), 'sold out at A');
        $this->assertNull($this->sellAt($this->b), 'still sellable at B');
    }

    public function test_a_global_mark_blocks_every_outlet(): void
    {
        $this->postJson("/api/v1/products/{$this->product->id}/sold-out", ['reason_code_id' => $this->reason()])->assertCreated();

        $this->assertNotNull($this->sellAt($this->a));
        $this->assertNotNull($this->sellAt($this->b));
    }

    public function test_the_same_outlet_cannot_be_marked_twice_but_another_can(): void
    {
        $url = "/api/v1/products/{$this->product->id}/sold-out";
        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->a->id]])->assertCreated();

        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->a->id]])->assertStatus(409);
        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->b->id]])->assertCreated();
    }

    public function test_undo_per_outlet_clears_only_the_named_outlet(): void
    {
        $url = "/api/v1/products/{$this->product->id}/sold-out";
        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->a->id, $this->b->id]])->assertCreated();

        $this->deleteJson($url, ['outlet_ids' => [$this->a->id]])->assertOk();

        $this->assertNull($this->sellAt($this->a));
        $this->assertNotNull($this->sellAt($this->b));
    }

    public function test_an_item_not_carried_by_an_outlet_cannot_be_ordered_there(): void
    {
        app(OutletMenuService::class)->setCarried($this->product, [$this->a->id]);

        $this->assertNull($this->sellAt($this->a));
        $this->assertStringContainsString('tidak dijual di outlet ini', (string) $this->sellAt($this->b));
    }

    public function test_the_poll_follows_the_selected_outlet(): void
    {
        $url = "/api/v1/products/{$this->product->id}/sold-out";
        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->a->id]])->assertCreated();

        $atA = $this->withHeader('X-Outlet-Id', (string) $this->a->id)->getJson('/api/v1/menu/availability')->assertOk()->json('sold_out');
        $atB = $this->withHeader('X-Outlet-Id', (string) $this->b->id)->getJson('/api/v1/menu/availability')->assertOk()->json('sold_out');

        $this->assertSame([$this->product->id], array_column($atA, 'product_id'));
        $this->assertSame([], $atB);
    }

    public function test_an_outlet_outside_the_permitted_set_is_refused(): void
    {
        $role = \App\Models\Role::create(['name' => 'Pengelola 86', 'menu_keys' => ['dashboard', 'products'], 'permissions' => ['menu.item.86'], 'all_outlets' => false]);
        $manager = User::factory()->create(['role_id' => $role->id]);
        $this->assignUser($manager, [$this->a]);
        $this->actingAs($manager, 'sanctum');
        $url = "/api/v1/products/{$this->product->id}/sold-out";

        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => [$this->b->id]])->assertStatus(422)->assertJsonValidationErrors('outlet_ids.0');
        $this->deleteJson($url, ['outlet_ids' => [$this->b->id]])->assertForbidden()->assertJsonPath('code', 'outlet_forbidden');
        $this->postJson($url, ['reason_code_id' => $this->reason(), 'outlet_ids' => []])->assertStatus(422);
    }

    public function test_the_selected_outlet_is_the_default_target_and_no_selection_means_global(): void
    {
        $url = "/api/v1/products/{$this->product->id}/sold-out";

        $this->withHeader('X-Outlet-Id', (string) $this->a->id)
            ->postJson($url, ['reason_code_id' => $this->reason()])->assertCreated()->assertJsonPath('outlet_ids', [$this->a->id]);
        $this->assertNull($this->sellAt($this->b), 'selecting A marked only A');

        $this->withHeader('X-Outlet-Id', (string) $this->a->id)->deleteJson($url)->assertOk();
        $this->withoutHeader('X-Outlet-Id')->postJson($url, ['reason_code_id' => $this->reason()])->assertCreated()->assertJsonPath('outlet_ids', null);
        $this->assertNotNull($this->sellAt($this->b), 'no selection = global mark');
    }
}
