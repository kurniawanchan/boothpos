<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'vendor_price_count' => $this->vendor_prices_count ?? null,
            // vendor_prices hanya disertakan bila controller memuat relasinya
            // (show), konsisten dengan gaya relationLoaded() di kodebase ini.
            'vendor_prices' => $this->when(
                $this->relationLoaded('vendorPrices'),
                fn () => VendorMaterialPriceResource::collection($this->vendorPrices)
            ),
        ];
    }
}
