<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\ProductCodeGenerator;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 068 (research D4, FR-014) — kode kategori dan segmen produk dialokasikan secara deterministik, bebas tabrakan
 * lintas mode dan terhadap baris terhapus, tanpa mengubah perilaku buildCodePrefix() yang lama.
 */
class WjmCatalogCodeAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function codes(): ProductCodeGenerator
    {
        return app(ProductCodeGenerator::class);
    }

    public function test_category_codes_use_initials_and_skip_codes_taken_in_any_mode_or_by_deleted_rows(): void
    {
        $this->assertSame('CO', $this->codes()->freeCategoryCode('Coffee'));
        $this->assertSame('MP', $this->codes()->freeCategoryCode('Mie & Pangsit Level'));
        $this->assertSame('NC', $this->codes()->freeCategoryCode('Non Coffee'));
        $this->assertSame('NA', $this->codes()->freeCategoryCode('Nasi'));

        ModeGate::runAs('demo', fn () => Category::factory()->create(['code' => 'NA', 'name' => 'Nasi Demo']));
        Category::factory()->create(['code' => 'NC', 'name' => 'Non Coffee Lama'])->delete();

        $this->assertSame('N1', $this->codes()->freeCategoryCode('Non Coffee'));
        Category::factory()->create(['code' => 'N1', 'name' => 'Nasi']);
        $this->assertSame('N2', $this->codes()->freeCategoryCode('Non Coffee'));
    }

    public function test_segment_candidates_follow_research_d4(): void
    {
        $this->assertSame('DIS', $this->codes()->segmentCandidates('Dimsum Isi 4')[0]);
        $this->assertSame('BAS', $this->codes()->segmentCandidates('Basreng')[0]);
        $this->assertSame(['RBC', 'RCO', 'ROT'], array_slice($this->codes()->segmentCandidates('Roti Bakar Coklat'), 0, 3));
        $this->assertSame('MIE', $this->codes()->segmentCandidates('Mie Level 3-5')[1], 'kandidat ganda dibuang (MLE muncul sekali)');
    }

    public function test_similar_names_in_one_category_get_distinct_segments_in_catalog_order(): void
    {
        $outlet = Outlet::factory()->create(['code' => 'WJ3']);
        $category = Category::factory()->create(['code' => 'RP', 'name' => 'Roti & Pisang']);
        $names = [
            'Pisang Bakar Coklat', 'Pisang Bakar Coklat Keju', 'Pisang Bakar Keju', 'Roti Bakar Blueberry', 'Roti Bakar Choco Crunchy',
            'Roti Bakar Coklat', 'Roti Bakar Coklat Keju', 'Roti Bakar Keju', 'Roti Bakar Strawberry', 'Roti Bakar Tiramisu Crunchy',
        ];

        $segments = [];
        foreach ($names as $name) {
            [$segment, $prefix] = $this->codes()->firstFreeCodePrefix('WJ3', 'RP', $name);
            $this->assertSame("WJ3-RP-{$segment}", $prefix);
            Product::factory()->create(['home_outlet_id' => $outlet->id, 'category_id' => $category->id, 'code_prefix' => $prefix, 'product_segment' => $segment, 'name' => $name]);
            $segments[] = $segment;
        }

        $this->assertSame(['PBC', 'PKE', 'PBK', 'RBB', 'RBC', 'RCO', 'RKE', 'RBK', 'RBS', 'RBT'], $segments);
    }

    public function test_prefixes_owned_by_deleted_products_or_other_modes_are_never_reused(): void
    {
        $outlet = Outlet::factory()->create(['code' => 'WJ3']);
        $category = Category::factory()->create(['code' => 'RP']);
        Product::factory()->create(['home_outlet_id' => $outlet->id, 'category_id' => $category->id, 'code_prefix' => 'WJ3-RP-RBB'])->delete();
        ModeGate::runAs('demo', fn () => Product::factory()->create([
            'home_outlet_id' => Outlet::factory()->create(['code' => 'ZD1'])->id,
            'category_id' => Category::factory()->create(['code' => 'ZD'])->id,
            'code_prefix' => 'WJ3-RP-RBL',
        ]));

        $this->assertSame(['ROT', 'WJ3-RP-ROT'], $this->codes()->firstFreeCodePrefix('WJ3', 'RP', 'Roti Bakar Blueberry'));
    }

    public function test_build_code_prefix_still_rejects_collisions(): void
    {
        $outlet = Outlet::factory()->create(['code' => 'WJ3']);
        $category = Category::factory()->create(['code' => 'RP']);
        Product::factory()->create(['home_outlet_id' => $outlet->id, 'category_id' => $category->id, 'code_prefix' => 'WJ3-RP-RBK']);

        $this->expectException(ValidationException::class);
        $this->codes()->buildCodePrefix('WJ3', 'RP', 'RBK');
    }
}
