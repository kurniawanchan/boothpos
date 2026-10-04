<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 034-seller-po-bom — bentuk skema BOM baru: baris PO bahan-sama boleh
 * berdampingan, baris PO yang SAMA tidak boleh dobel, baris layanan tidak
 * punya bahan, dan baris PO yang dipakai BOM tidak bisa dihapus.
 */
class BomSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function variant(): ProductVariant
    {
        $artist = Artist::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => Category::factory()->create()->id]);

        return $product->variants()->create(['sku' => 'SCH'.fake()->unique()->numerify('######'), 'sell_price' => 10000, 'cost_price' => 0, 'current_stock' => 0]);
    }

    private function poLine(Material $material, float $price = 500, string $type = 'material'): PurchaseOrderItem
    {
        $po = PurchaseOrder::factory()->create(['status' => 'ordered']);

        return $po->items()->create([
            'line_type' => $type,
            'material_id' => $type === 'material' ? $material->id : null,
            'description' => $type === 'service' ? 'Perakitan' : null,
            'qty' => 100, 'unit_price' => $price, 'line_total' => 100 * $price,
        ]);
    }

    public function test_two_po_lines_of_the_same_material_can_coexist_in_one_bom(): void
    {
        $variant = $this->variant();
        $material = Material::factory()->create();

        $a = ProductVariantBomLine::factory()->fromPoLine($this->poLine($material, 500))->create(['product_variant_id' => $variant->id]);
        $b = ProductVariantBomLine::factory()->fromPoLine($this->poLine($material, 700))->create(['product_variant_id' => $variant->id]);

        $this->assertSame(2, $variant->bomLines()->count());
        $this->assertFalse($a->isLegacy());
        $this->assertNotSame($a->purchase_order_item_id, $b->purchase_order_item_id);
    }

    public function test_the_same_po_line_cannot_be_added_twice_to_one_variant(): void
    {
        $variant = $this->variant();
        $item = $this->poLine(Material::factory()->create());

        ProductVariantBomLine::factory()->fromPoLine($item)->create(['product_variant_id' => $variant->id]);

        $this->expectException(QueryException::class);
        ProductVariantBomLine::factory()->fromPoLine($item)->create(['product_variant_id' => $variant->id]);
    }

    public function test_legacy_rows_of_different_materials_and_service_rows_without_material_are_allowed(): void
    {
        $variant = $this->variant();

        ProductVariantBomLine::factory()->create(['product_variant_id' => $variant->id]);
        ProductVariantBomLine::factory()->create(['product_variant_id' => $variant->id]);
        $service = ProductVariantBomLine::factory()
            ->fromPoLine($this->poLine(Material::factory()->create(), 1000, 'service'))
            ->create(['product_variant_id' => $variant->id]);

        $this->assertNull($service->material_id);
        $this->assertSame('service', $service->line_type);
        $this->assertSame(2, $variant->bomLines()->whereNull('purchase_order_item_id')->count());
    }

    public function test_a_po_line_used_by_a_bom_row_cannot_be_deleted(): void
    {
        $variant = $this->variant();
        $item = $this->poLine(Material::factory()->create());
        ProductVariantBomLine::factory()->fromPoLine($item)->create(['product_variant_id' => $variant->id]);

        $this->expectException(QueryException::class);
        $item->delete();
    }

    public function test_eligible_scope_only_returns_the_sellers_ordered_received_or_paid_lines(): void
    {
        $seller = Artist::factory()->create();
        $other = Artist::factory()->create();
        $material = Material::factory()->create();

        $make = function (Artist $artist, string $status) use ($material) {
            $po = PurchaseOrder::factory()->create(['artist_id' => $artist->id, 'status' => $status]);

            return $po->items()->create(['line_type' => 'material', 'material_id' => $material->id, 'qty' => 1, 'unit_price' => 1, 'line_total' => 1]);
        };

        $ok = [$make($seller, 'ordered'), $make($seller, 'received'), $make($seller, 'paid')];
        $make($seller, 'draft');
        $make($seller, 'cancelled');
        $make($other, 'ordered');
        PurchaseOrder::factory()->legacy()->create(['status' => 'ordered'])->items()
            ->create(['line_type' => 'material', 'material_id' => $material->id, 'qty' => 1, 'unit_price' => 1, 'line_total' => 1]);

        $ids = PurchaseOrderItem::eligibleForSeller($seller->id)->pluck('id')->sort()->values()->all();

        $this->assertSame(collect($ok)->pluck('id')->sort()->values()->all(), $ids);
    }
}
