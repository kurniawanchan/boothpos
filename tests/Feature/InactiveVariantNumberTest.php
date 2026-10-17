<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Spec 045 FR-017: varian nonaktif (ber-riwayat) tidak menahan nomornya. */
class InactiveVariantNumberTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $artist = Artist::factory()->create(['code' => 'SPF']);
        $category = Category::factory()->create(['code' => 'KC']);
        $this->product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id,
            'code_prefix' => 'SPF-KC-S', 'product_segment' => 'S',
        ]);
        $this->product->variants()->create(['sku' => 'SPF-KC-S-1', 'variant_name' => 'A', 'sell_price' => 15000, 'current_stock' => 0]);
        $this->product->variants()->create(['sku' => 'SPF-KC-S-2', 'variant_name' => 'B', 'sell_price' => 15000, 'current_stock' => 0]);
        $this->product->variants()->create(['sku' => 'SPF-KC-S-25', 'variant_name' => 'Tersembunyi', 'sell_price' => 15000, 'current_stock' => 0, 'is_active' => false]);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function variant(string $sku): ProductVariant
    {
        return ProductVariant::where('sku', $sku)->firstOrFail();
    }

    public function test_a_deactivated_variants_number_can_be_typed_by_another_variant(): void
    {
        $this->putJson("/api/v1/variants/{$this->variant('SPF-KC-S-2')->id}", ['sequence' => '25'])->assertOk();

        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-KC-S-25', 'is_active' => true]);
    }

    public function test_the_generated_number_ignores_deactivated_variants(): void
    {
        $this->postJson("/api/v1/products/{$this->product->id}/variants", [
            'variant_name' => 'Baru', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'SPF-KC-S-3');
    }

    public function test_an_active_duplicate_is_still_refused(): void
    {
        $this->putJson("/api/v1/variants/{$this->variant('SPF-KC-S-1')->id}", ['sequence' => '2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }

    public function test_two_deactivated_variants_may_share_a_sku_in_the_database(): void
    {
        $this->product->variants()->create(['sku' => 'SPF-KC-S-30', 'variant_name' => 'X', 'sell_price' => 15000, 'current_stock' => 0, 'is_active' => false]);

        DB::table('product_variants')->insert([
            'product_id' => $this->product->id, 'sku' => 'SPF-KC-S-30', 'variant_name' => 'Y',
            'cost_price' => 0, 'sell_price' => 15000, 'current_stock' => 0, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(2, ProductVariant::withTrashed()->where('sku', 'SPF-KC-S-30')->count());
    }

    public function test_a_segment_change_leaves_the_deactivated_variant_alone(): void
    {
        $this->putJson("/api/v1/products/{$this->product->id}", ['name' => $this->product->name, 'product_segment' => 'T'])->assertOk();

        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-KC-S-25', 'is_active' => false]);
    }

    public function test_reactivating_a_variant_whose_number_an_active_one_uses_is_refused(): void
    {
        $hidden = $this->variant('SPF-KC-S-25');
        $this->putJson("/api/v1/variants/{$this->variant('SPF-KC-S-1')->id}", ['sequence' => '25'])->assertOk();

        $this->putJson("/api/v1/variants/{$hidden->id}", ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }
}
