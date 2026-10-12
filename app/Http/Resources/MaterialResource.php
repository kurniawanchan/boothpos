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
            // 045 Stage 5 — saldo per outlet yang berlaku (hanya bila relasinya dimuat); `current_stock` tetap total.
            'outlet_stock' => $this->when($this->relationLoaded('outletStock'), fn () => $this->outletStock->map(fn ($b) => [
                'outlet_id' => $b->outlet_id, 'outlet_code' => $b->outlet?->code, 'outlet_name' => $b->outlet?->name,
                'on_hand' => number_format((float) $b->current_stock, 3, '.', ''),
            ])->values()),
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
