<?php

namespace App\Http\Resources\Storefront;

use App\Models\Product;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §6) — kartu produk untuk daftar etalase.
 *
 * Proyeksi PUBLIK tersendiri; sengaja tidak mewarisi atau memakai
 * ProductResource staf. Pengenal publik produk adalah `slug`: id numerik,
 * `code_prefix`, segmen, jalur berkas gambar, status aktif/terbit, mode data
 * dan kontak seller TIDAK PERNAH keluar. Seller hanya tampil sebagai nama.
 *
 * Mengandalkan StorefrontCatalogService yang sudah memuat `artist`,
 * `category`, dan agregat `price_min`/`price_max` (varian yang ditawarkan
 * saja). Field baru di sini berarti field baru untuk publik: tambahkan dengan
 * sadar dan perbarui StorefrontRedactionTest.
 *
 * @mixin Product
 */
class StorefrontProductCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'image_url' => app(ImageUploadService::class)->url($this->image_path),
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ],
            'seller_name' => $this->artist->name,
            'price_min' => number_format((float) $this->price_min, 2, '.', ''),
            'price_max' => number_format((float) $this->price_max, 2, '.', ''),
            // Produk hanya tampil bila punya varian berstok, jadi rentang harganya selalu ada.
            'in_stock' => $this->price_min !== null,
        ];
    }
}
