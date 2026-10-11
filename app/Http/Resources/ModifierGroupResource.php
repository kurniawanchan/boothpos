<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ModifierGroup */
class ModifierGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'selection_type' => $this->selection_type,
            'is_required' => $this->is_required,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'price_delta' => number_format((float) $o->price_delta, 2, '.', ''),
                'display_order' => $o->display_order,
                'is_active' => $o->is_active,
            ])->values()),
            'product_ids' => $this->whenLoaded('products', fn () => $this->products->pluck('id')->values()),
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->values()),
        ];
    }
}
