<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'variant_name' => $this->variant_name,
            'cost_price' => number_format((float) $this->cost_price, 2, '.', ''),
            'sell_price' => number_format((float) $this->sell_price, 2, '.', ''),
            'current_stock' => $this->current_stock,
            'low_stock_alert' => $this->low_stock_alert,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
        ];
    }
}
