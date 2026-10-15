<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantBomLineFactory extends Factory
{
    protected $model = \App\Models\ProductVariantBomLine::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'material_id' => Material::factory(),
            'qty_needed' => fake()->randomFloat(4, 0.5, 10),
            'notes' => null,
        ];
    }

    /** Baris yang bersumber dari baris PO: menyalin snapshot seperti VariantBomService. */
    public function fromPoLine(\App\Models\PurchaseOrderItem $item): static
    {
        return $this->state(function () use ($item) {
            $item->loadMissing(['purchaseOrder.vendor', 'material']);

            return [
                'material_id' => $item->material_id,
                'purchase_order_item_id' => $item->id,
                'line_type' => $item->line_type,
                'item_name' => $item->line_type === 'material' ? $item->material?->name : $item->description,
                'po_number' => $item->purchaseOrder->po_number,
                'vendor_id' => $item->purchaseOrder->vendor_id,
                'vendor_name' => $item->purchaseOrder->vendor?->name,
                'unit_cost' => $item->unit_price,
                'qty_needed' => 1,
            ];
        });
    }
}
