<?php

namespace App\Http\Resources\Storefront;

use App\Models\Category;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §6) — kategori untuk etalase publik.
 *
 * Proyeksi PUBLIK tersendiri; sengaja tidak mewarisi atau memakai
 * CategoryResource staf (yang membawa kode, jalur berkas, induk, urutan,
 * status aktif, jumlah produk). Field baru di sini berarti field baru untuk
 * publik: tambahkan dengan sadar dan perbarui StorefrontRedactionTest.
 *
 * @mixin Category
 */
class StorefrontCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => app(ImageUploadService::class)->url($this->image_path),
        ];
    }
}
