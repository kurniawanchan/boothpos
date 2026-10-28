<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 079 / US3 — kode outlet ikut tertanam di kode produk/SKU saat dibuat dan tidak pernah ditulis ulang. */
class OutletCodeProductSkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function createProduct(Outlet $outlet, Category $category, string $name): Product
    {
        $id = $this->postJson('/api/v1/products', [
            'home_outlet_id' => $outlet->id, 'category_id' => $category->id, 'name' => $name,
            'variants' => [['variant_name' => 'Reguler', 'sell_price' => 10000]],
        ])->assertCreated()->json('id');

        return Product::with('variants')->findOrFail($id);
    }

    public function test_editing_the_outlet_code_keeps_old_codes_and_new_products_use_the_new_code(): void
    {
        $outlet = Outlet::factory()->create(['code' => 'WJM']);
        $category = Category::factory()->create(['code' => 'MN']);

        $before = $this->createProduct($outlet, $category, 'Kopi Susu');
        $this->assertSame('WJM-MN-KOP', $before->code_prefix);
        $this->assertSame('WJM-MN-KOP-001', $before->variants[0]->sku);

        $this->putJson("/api/v1/outlets/{$outlet->id}", ['code' => 'WJM2X', 'name' => $outlet->name])->assertOk();

        $this->assertSame('WJM-MN-KOP', $before->fresh()->code_prefix);
        $this->assertSame('WJM-MN-KOP-001', $before->fresh()->variants[0]->sku);

        $after = $this->createProduct($outlet->fresh(), $category, 'Teh Manis');
        $this->assertSame('WJM2X-MN-TEH', $after->code_prefix);
        $this->assertSame('WJM2X-MN-TEH-001', $after->variants[0]->sku);
    }

    public function test_a_five_character_code_fits_the_widened_prefix_and_sku_columns(): void
    {
        $product = $this->createProduct(Outlet::factory()->create(['code' => 'KD001']), Category::factory()->create(['code' => 'MN']), 'Roti Bakar');

        $this->assertSame(12, strlen($product->code_prefix));
        $this->assertSame(16, strlen($product->variants[0]->sku));
    }

    public function test_three_letter_outlets_still_generate_the_old_widths(): void
    {
        $product = $this->createProduct(Outlet::factory()->create(['code' => 'ABC']), Category::factory()->create(['code' => 'MN']), 'Nasi Goreng');

        $this->assertSame(10, strlen($product->code_prefix));
        $this->assertSame(14, strlen($product->variants[0]->sku));
    }

    public function test_every_column_that_copies_a_sku_or_prefix_is_wide_enough(): void
    {
        $length = function (string $table, string $column): int {
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type'];
            preg_match('/\((\d+)\)/', $type, $m);

            return (int) $m[1];
        };

        $this->assertGreaterThanOrEqual(12, $length('products', 'code_prefix'));
        foreach ([['product_variants', 'sku'], ['order_items', 'sku_snapshot'], ['preorder_items', 'sku_snapshot']] as [$table, $column]) {
            $this->assertGreaterThanOrEqual(16, $length($table, $column), "{$table}.{$column}");
        }
        $this->assertGreaterThanOrEqual(5, $length('outlets', 'code'));
    }
}
