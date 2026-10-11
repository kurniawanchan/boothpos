<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T005, T014) — status turunan produk dan migrasi pengisian siklus hidup.
 */
class ProductLifecycleBackfillTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_11_05_000001_add_facts_and_lifecycle_to_products.php'))->up();
    }

    public function test_the_migration_backfills_published_and_discontinued_and_is_rerunnable(): void
    {
        $active = $this->makeVariant(stock: 1)->product;
        $inactive = $this->makeVariant(stock: 1)->product;
        DB::table('products')->where('id', $inactive->id)->update(['is_active' => 0]);
        DB::table('products')->whereIn('id', [$active->id, $inactive->id])->update(['published_at' => null, 'discontinued_at' => null]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(Product::STATUS_ACTIVE, Product::find($active->id)->status);
        $this->assertNotNull(Product::find($active->id)->published_at);
        $this->assertSame(Product::STATUS_DISCONTINUED, Product::find($inactive->id)->status);
    }

    public function test_status_is_derived_from_the_three_columns(): void
    {
        $p = $this->makeVariant(stock: 1)->product;

        $this->assertSame('active', $p->fresh()->status);

        $p->forceFill(['published_at' => null, 'is_active' => false])->save();
        $this->assertSame('draft', $p->fresh()->status);

        $p->forceFill(['published_at' => now(), 'discontinued_at' => now()])->save();
        $this->assertSame('discontinued', $p->fresh()->status);
    }

    public function test_a_product_created_active_is_published_and_one_created_inactive_is_a_draft(): void
    {
        $base = $this->makeVariant(stock: 1)->product;
        $new = fn (bool $active) => Product::create([
            'artist_id' => $base->artist_id, 'category_id' => $base->category_id, 'code_prefix' => 'ZZ'.random_int(100000, 999999),
            'product_segment' => 'ZZZ', 'name' => 'Baru', 'is_active' => $active,
        ])->fresh();

        $this->assertSame('active', $new(true)->status);
        $this->assertSame('draft', $new(false)->status);
    }

    public function test_deactivating_discontinues_and_reactivating_publishes_again_whoever_writes_is_active(): void
    {
        $p = $this->makeVariant(stock: 1)->product;

        $p->update(['is_active' => false]);
        $this->assertSame('discontinued', $p->fresh()->status);
        $this->assertNotNull($p->fresh()->discontinued_at);

        $p->update(['is_active' => true]);
        $this->assertSame('active', $p->fresh()->status);
        $this->assertNull($p->fresh()->discontinued_at);
    }

    public function test_client_input_can_never_set_the_lifecycle_columns(): void
    {
        $p = $this->makeVariant(stock: 1)->product;

        $p->fill(['published_at' => '2000-01-01', 'discontinued_at' => '2000-01-01'])->save();

        $this->assertNull($p->fresh()->discontinued_at);
        $this->assertNotSame('2000-01-01', optional($p->fresh()->published_at)->toDateString());
    }
}
