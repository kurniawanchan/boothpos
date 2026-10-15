<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'material_id' => $this->material_id,
            'material_name' => $this->whenLoaded('material', fn () => $this->material->name),
            'material_unit' => $this->whenLoaded('material', fn () => $this->material->unit),
            'qty_needed' => number_format((float) $this->qty_needed, 4, '.', ''),
            'notes' => $this->notes,
            // 034-seller-po-bom — baris tanpa baris PO adalah baris LEGACY.
            'is_legacy' => $this->isLegacy(),
            'line_type' => $this->line_type,
            'item_name' => $this->item_name,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'po_number' => $this->po_number,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor_name,
            'unit_cost' => $this->unit_cost !== null ? number_format((float) $this->unit_cost, 2, '.', '') : null,
        ];
    }
}
