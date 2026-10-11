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
            'kind' => $this->kind ?? 'ingredient',
            'standard_cost' => $this->standard_cost === null ? null : number_format((float) $this->standard_cost, 4, '.', ''),
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            // 006-purchase-order-and-ops (US1) — sebelumnya materials tidak
            // punya konsep stok sama sekali; lihat research.md R4.
            'current_stock' => number_format((float) $this->current_stock, 3, '.', ''),
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
