<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk response dikunci secara eksplisit sesuai skema Artist di
 * openapi-pos-mvp.yaml. Ini mencegah excessive data exposure — kolom baru
 * yang kelak ditambahkan ke tabel tidak otomatis ikut ter-expose ke API
 * hanya karena ada di model.
 */
class ArtistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            // products_count diisi controller via withCount('products') untuk
            // menghindari N+1. Bernilai null (bukan error) bila controller
            // tidak memuatnya, misalnya pada response create/update.
            'product_count' => $this->products_count ?? null,
        ];
    }
}
