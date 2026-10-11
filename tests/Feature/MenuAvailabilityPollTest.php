<?php

namespace Tests\Feature;

use App\Models\ItemAvailabilityMark;
use App\Models\ReasonCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T043) — GET /menu/availability.
 */
class MenuAvailabilityPollTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
    }

    private function mark($product): void
    {
        ItemAvailabilityMark::create(['product_id' => $product->id, 'reason_code_id' => ReasonCode::where('category', 'sold_out')->value('id'), 'marked_at' => now(), 'expires_at' => now()->addHour()]);
    }

    public function test_it_lists_sold_out_products_with_their_variants_and_discontinued_ids(): void
    {
        $a = $this->makeVariant(stock: 1);
        $b = $this->makeVariant(stock: 1);
        $b->product->update(['is_active' => false]);
        $this->mark($a->product);

        $json = $this->getJson('/api/v1/menu/availability')->assertOk()->json();

        $this->assertSame($a->product_id, $json['sold_out'][0]['product_id']);
        $this->assertSame([$a->id], $json['sold_out'][0]['variant_ids']);
        $this->assertSame([$b->product_id], $json['discontinued_ids']);
        $this->assertSame(0, $json['menu_version']);
        $this->assertNotEmpty($json['etag']);
    }

    public function test_a_matching_etag_returns_304_and_a_change_produces_a_new_etag(): void
    {
        $a = $this->makeVariant(stock: 1);
        $etag = $this->getJson('/api/v1/menu/availability')->json('etag');

        $this->getJson('/api/v1/menu/availability?since='.$etag)->assertStatus(304);

        $this->mark($a->product);
        $second = $this->getJson('/api/v1/menu/availability?since='.$etag)->assertOk()->json();
        $this->assertNotSame($etag, $second['etag']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/menu/availability')->assertUnauthorized();
    }
}
