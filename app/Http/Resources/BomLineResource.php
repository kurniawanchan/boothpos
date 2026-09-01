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
        ];
    }
}
