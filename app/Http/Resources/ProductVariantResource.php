<?php

namespace App\Http\Resources;

use App\Services\BomCostCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'image_path' => $this->image_path,
            // Same pattern as ProductResource — the parent product's image
            // is a separate, independent field (Product.image_path), not a
            // fallback for a variant with no image of its own.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'variant_name' => $this->variant_name,
            'cost_price' => number_format((float) $this->cost_price, 2, '.', ''),
            'sell_price' => number_format((float) $this->sell_price, 2, '.', ''),
            'current_stock' => $this->current_stock,
            'low_stock_alert' => $this->low_stock_alert,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
            // 034-seller-po-bom — selama true, cost_price mengikuti biaya BOM
            // dan dikunci terhadap edit manual.
            'bom_complete' => (bool) $this->bom_complete,
            // has_bom/bom_cost hanya ada bila baris BOM sengaja dimuat
            // pemanggil (ProductController::variantRelations) — daftar
            // produk/POS tidak menarik BOM varian satu per satu.
            $this->mergeWhen($this->relationLoaded('bomLines'), fn () => [
                'has_bom' => $this->bomLines->isNotEmpty(),
                'bom_cost' => $this->bomLines->isNotEmpty()
                    ? app(BomCostCalculator::class)->breakdown($this->resource)['bom_cost']
                    : null,
            ]),
        ];
    }
}
