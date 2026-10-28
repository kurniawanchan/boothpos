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
            // Segmen tersimpan (spec 044 US4). Kolom product_segment NOT NULL, jadi selalu ada.
            'product_segment' => $this->product_segment,
            'name' => $this->name,
            'description' => $this->description,
            'image_path' => $this->image_path,
            // URL siap-pakai (Task 5), sama dengan CategoryResource.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'is_preorder' => $this->is_preorder,
            'preorder_eta' => $this->preorder_eta?->toDateString(),
            'is_active' => $this->is_active,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            // 048 — hanya ada bila query daftar menghitungnya (jebakan relationLoaded yang SENGAJA dipakai):
            // respons show/store/update tidak memuatnya, jadi tidak pernah menampilkan nol palsu.
            $this->mergeWhen(array_key_exists('total_stock', $this->resource->getAttributes()), fn () => [
                'total_stock' => (int) $this->total_stock,
                'total_stock_value' => number_format((float) $this->total_stock_value, 2, '.', ''),
            ]),
        ];
    }
}
