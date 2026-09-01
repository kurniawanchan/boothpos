<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorMaterialPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->whenLoaded('vendor', fn () => $this->vendor->name),
            'material_id' => $this->material_id,
            'price' => number_format((float) $this->price, 2, '.', ''),
            'is_preferred' => $this->is_preferred,
            'notes' => $this->notes,
        ];
    }
}
