<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\MenuChange;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\VariantOutletPrice;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 070 (US3) — `POST /products` menerima `variants.*.outlet_prices` dan menuliskannya dalam transaksi yang sama dengan
 * item, lewat PriceService (satu-satunya penulis harga). Harga outlet saat membuat ditulis LANGSUNG (source `created`),
 * sejajar dengan harga dasar: item baru draft saat rilis aktif, jadi belum terjual sebelum terbit.
 */
class ProductStoreOutletPricesTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private $a;

    private $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->a, $this->b] = $this->makeOutlets(2)->all();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
    }

    private function payload(array $variants, array $extra = []): array
    {
        return array_merge([
            'home_outlet_id' => $this->a->id,
            'category_id' => Category::factory()->create()->id,
            'name' => 'Mie Level 1-2',
            'variants' => $variants,
        ], $extra);
    }

    private function variant(string $name, int $price, array $outletPrices = []): array
    {
        return ['variant_name' => $name, 'sell_price' => $price] + ($outletPrices === [] ? [] : ['outlet_prices' => $outletPrices]);
    }

    private function release(bool $on): void
    {
        Setting::updateOrCreate(['key' => 'menu_release_enabled'], ['value' => $on ? 'true' : 'false', 'type' => 'boolean']);
    }

    public function test_outlet_prices_are_stored_with_the_item_and_written_through_the_price_service(): void
    {
        $this->release(false);

        $res = $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000]]),
            $this->variant('Ice', 16000),
        ]))->assertCreated();

        $variants = collect($res->json('variants'))->keyBy('variant_name');
        $hot = $variants['Hot']['id'];
        $this->assertSame(1, VariantOutletPrice::count());
        $this->assertEquals(17000, VariantOutletPrice::where('variant_id', $hot)->where('outlet_id', $this->b->id)->value('price'));
        $history = PriceHistory::where('variant_id', $hot)->where('outlet_id', $this->b->id)->firstOrFail();
        $this->assertSame('created', $history->source);
        $this->assertEquals(15000, $history->old_price, 'riwayat memakai harga dasar sebagai harga sebelumnya');
        $this->assertEquals(17000, $history->new_price);
        $this->assertTrue(ActivityLog::where('action', 'outlet_price_changed')->where('entity_id', $hot)->exists(), 'audit ditulis PriceService');
    }

    public function test_the_item_page_shows_exactly_what_was_entered(): void
    {
        $this->release(false);
        $id = $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000]]),
        ]))->assertCreated()->json('id');

        $show = $this->getJson("/api/v1/products/{$id}")->assertOk()->json();

        $hot = $show['variants'][0]['id'];
        $this->assertSame([$this->b->id => '17000.00'], $show['outlet_prices']['by_variant'][$hot]);
    }

    public function test_with_the_release_on_the_price_is_still_written_directly_and_no_price_change_is_queued(): void
    {
        $this->release(true);

        $res = $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000]]),
        ]))->assertCreated();

        $this->assertEquals(17000, VariantOutletPrice::where('outlet_id', $this->b->id)->value('price'));
        $this->assertSame(0, MenuChange::where('kind', MenuChange::KIND_PRICE)->count(), 'item draft: tidak ada perubahan harga yang menunggu rilis');
        $this->assertSame('draft', Product::findOrFail($res->json('id'))->status);
    }

    public function test_an_outlet_price_equal_to_the_base_price_creates_no_row(): void
    {
        $this->release(false);

        $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 15000]]),
        ]))->assertCreated();

        $this->assertSame(0, VariantOutletPrice::count(), 'baris harga outlet hanya ada bila berbeda dari harga dasar (konvensi VariantsSection)');
    }

    public function test_an_outlet_the_user_may_not_see_is_refused(): void
    {
        $user = $this->assignUser(User::factory()->create(['role' => 'inventory']), [$this->a]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000]]),
        ]))->assertUnprocessable()->assertJsonValidationErrors('variants.0.outlet_prices.0.outlet_id');

        $this->assertSame(0, Product::count());
    }

    public function test_an_outlet_that_does_not_carry_the_item_is_refused(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000]]),
        ], ['outlet_ids' => [$this->a->id]]))->assertUnprocessable()->assertJsonValidationErrors('variants.0.outlet_prices.0.outlet_id');

        $this->assertSame(0, Product::count());
    }

    public function test_the_same_outlet_twice_for_one_variant_is_refused(): void
    {
        $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000], ['outlet_id' => $this->b->id, 'price' => 18000]]),
        ]))->assertUnprocessable()->assertJsonValidationErrors('variants.0.outlet_prices.1.outlet_id');
    }

    public function test_bad_prices_are_refused(): void
    {
        foreach ([-1, 'abc', null] as $bad) {
            $this->postJson('/api/v1/products', $this->payload([
                $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => $bad]]),
            ]))->assertUnprocessable()->assertJsonValidationErrors('variants.0.outlet_prices.0.price');
        }
        $this->assertSame(0, Product::count());
    }

    public function test_a_failure_while_writing_prices_leaves_no_half_saved_item(): void
    {
        $this->partialMock(PriceService::class, fn ($m) => $m->shouldReceive('writeOutletPrice')->once()->andThrow(new \RuntimeException('gagal tulis harga')));

        $this->postJson('/api/v1/products', $this->payload([
            $this->variant('Hot', 15000, [['outlet_id' => $this->b->id, 'price' => 17000]]),
            $this->variant('Ice', 16000),
        ]))->assertStatus(500);

        $this->assertSame(0, Product::count());
        $this->assertSame(0, \App\Models\ProductVariant::count());
        $this->assertSame(0, VariantOutletPrice::count());
        $this->assertSame(0, PriceHistory::where('source', 'created')->whereNotNull('outlet_id')->count());
    }

    public function test_omitting_outlet_prices_keeps_the_old_behaviour(): void
    {
        $this->release(false);

        $this->postJson('/api/v1/products', $this->payload([$this->variant('Hot', 15000)]))->assertCreated();

        $this->assertSame(0, VariantOutletPrice::count());
    }
}
