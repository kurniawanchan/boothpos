<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'artist_id' => $this->artist_id,
            'artist_name' => $this->whenLoaded('artist', fn () => $this->artist->name),
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category->name),
            'code_prefix' => $this->code_prefix,
            'name' => $this->name,
            'description' => $this->description,
            'image_path' => $this->image_path,
            // URL siap-pakai (Task 5), sama dengan CategoryResource.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'is_preorder' => $this->is_preorder,
            'preorder_eta' => $this->preorder_eta?->toDateString(),
            'is_active' => $this->is_active,
            'station_id' => $this->station_id,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            // 028 — hanya bila diminta (?with_modifiers=1): grup EFEKTIF yang aktif beserta opsi aktifnya.
            'modifier_groups' => $this->when($this->relationLoaded('modifierGroups'), fn () => app(\App\Services\ModifierResolver::class)
                ->effectiveGroups($this->resource)
                ->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name, 'selection_type' => $g->selection_type,
                    'is_required' => $g->is_required, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
                    'options' => $g->options->map(fn ($o) => [
                        'id' => $o->id, 'name' => $o->name, 'price_delta' => number_format((float) $o->price_delta, 2, '.', ''),
                    ])->values(),
                ])->values()),
        ];
    }
}
