<?php

namespace Tests\Support;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Vendor;
use App\Support\ModeGate;

/**
 * 034-seller-po-bom — fixture bersama untuk tes BOM: varian milik seller,
 * purchase order beserta barisnya (status/vendor/tanggal bebas), dan
 * pemanggil endpoint selector/tambah. Satu definisi, dipakai semua file
 * VariantBom*Test supaya bentuk data tes tidak menyimpang antar-file.
 */
trait BuildsBomFixtures
{
    private function variantFor(Artist $artist, ?string $sku = null): ProductVariant
    {
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => Category::factory()->create()->id]);

        return $product->variants()->create([
            'sku' => $sku ?? 'VB'.fake()->unique()->numerify('########'),
            'variant_name' => 'Red', 'sell_price' => 20000, 'cost_price' => 0, 'current_stock' => 0,
        ]);
    }

    /**
     * Buat PO beserta barisnya. $lines: [['type' => 'material'|'service', 'name' => string, 'price' => float, 'qty' => float]]
     *
     * @return PurchaseOrderItem[]
     */
    private function po(?Artist $artist, string $status, array $lines, string $vendorName = 'Vendor X', ?string $orderedAt = null, ?string $number = null): array
    {
        $po = PurchaseOrder::factory()->create(array_filter([
            'artist_id' => $artist?->id,
            'status' => $status,
            'vendor_id' => Vendor::firstOrCreate(['name' => $vendorName], ['code' => strtoupper(substr(md5($vendorName.ModeGate::current()), 0, 6))])->id,
            'ordered_at' => $orderedAt,
            'po_number' => $number,
        ], fn ($v) => $v !== null));
        if ($artist === null) {
            $po->update(['artist_id' => null]);
        }

        return collect($lines)->map(function (array $l) use ($po) {
            $isMaterial = $l['type'] === 'material';

            return $po->items()->create([
                'line_type' => $l['type'],
                'material_id' => $isMaterial ? Material::firstOrCreate(['name' => $l['name']], ['code' => strtoupper(substr(md5($l['name'].ModeGate::current()), 0, 8)), 'unit' => 'pcs'])->id : null,
                'description' => $isMaterial ? null : $l['name'],
                'qty' => $l['qty'] ?? 1000,
                'unit_price' => $l['price'],
                'line_total' => ($l['qty'] ?? 1000) * $l['price'],
            ]);
        })->all();
    }

    private function eligible(ProductVariant $variant, string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/variants/{$variant->id}/bom/eligible-lines".($query ? "?{$query}" : ''));
    }

    private function addItems(ProductVariant $variant, array $items): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/variants/{$variant->id}/bom/items", ['items' => $items]);
    }
}
