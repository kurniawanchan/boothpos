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
            'portion_per_option' => (bool) $this->portion_per_option,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'price_delta' => number_format((float) $o->price_delta, 2, '.', ''),
                'display_order' => $o->display_order,
                'is_active' => $o->is_active,
                // 087 — bahan per porsi; hanya bila relasinya dimuat (jalur grup modifier selalu memuatnya).
                ...($o->relationLoaded('materials') ? ['materials' => $o->materials->map(fn ($m) => [
                    'material_id' => $m->material_id,
                    'code' => $m->material?->code,
                    'name' => $m->material?->name,
                    'unit' => $m->material?->unit,
                    'quantity' => number_format((float) $m->quantity, 4, '.', ''),
                    'material_active' => (bool) ($m->material?->is_active && ! $m->material?->trashed()),
                ])->values()] : []),
            ])->values()),
            'product_ids' => $this->whenLoaded('products', fn () => $this->products->pluck('id')->values()),
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')->values()),
        ];
    }
}
