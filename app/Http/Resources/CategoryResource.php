<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'image_path' => $this->image_path,
            // URL siap-pakai (Task 5), bukan cuma path mentah — supaya
            // frontend tidak perlu tahu disk/konvensi penyimpanan.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'parent_id' => $this->parent_id,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'product_count' => $this->products_count ?? null,
        ];
    }
}
