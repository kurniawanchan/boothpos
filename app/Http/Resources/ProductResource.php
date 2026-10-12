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
            'home_outlet_id' => $this->home_outlet_id,
            'home_outlet_name' => $this->whenLoaded('homeOutlet', fn () => $this->homeOutlet->name),
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category->name),
            'code_prefix' => $this->code_prefix,
            'name' => $this->name,
            'description' => $this->description,
            'image_path' => $this->image_path,
            // URL siap-pakai (Task 5), sama dengan CategoryResource.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'is_active' => $this->is_active,
            'station_id' => $this->station_id,
            // 045 Stage 3 — outlet yang boleh dilihat pengguna dan apakah masing-masing membawa produk ini. Hanya ada bila
            // beberapa outlet terlihat DAN relasi `outletItemSettings.outlet` dimuat (show/store/update); disembunyikan di satu outlet.
            'outlets' => $this->when(
                $this->relationLoaded('outletItemSettings') && app(\App\Support\OutletContext::class)->isMulti(),
                fn () => $this->visibleOutletStates()
            ),
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

    /** @return list<array{id: int, code: string, name: string, carried: bool}> */
    private function visibleOutletStates(): array
    {
        $permitted = app(\App\Support\OutletContext::class)->permittedIds();

        return $this->outletItemSettings
            ->filter(fn ($row) => in_array((int) $row->outlet_id, $permitted, true) && $row->outlet !== null)
            ->sortBy('outlet_id')
            ->map(fn ($row) => ['id' => (int) $row->outlet_id, 'code' => $row->outlet->code, 'name' => $row->outlet->name, 'carried' => (bool) $row->is_carried])
            ->values()->all();
    }
}
