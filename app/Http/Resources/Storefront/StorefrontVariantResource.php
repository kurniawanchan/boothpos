<?php

namespace App\Http\Resources\Storefront;

use App\Models\ProductVariant;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §6) — varian untuk detail produk etalase.
 *
 * Proyeksi PUBLIK tersendiri; sengaja tidak mewarisi atau memakai
 * ProductVariantResource staf. Yang TIDAK PERNAH keluar: harga modal, stok
 * persisnya, ambang stok menipis, SKU, jalur berkas gambar, field BOM, mode
 * data. Sebagai ganti stok, pelanggan hanya melihat `max_qty` (batas beli
 * per baris keranjang).
 *
 * @mixin ProductVariant
 */
class StorefrontVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = app(ImageUploadService::class);

        return [
            // Id varian memang publik: aplikasi mengirimnya kembali sebagai baris keranjang.
            'id' => $this->id,
            'name' => $this->variant_name,
            // Gambar varian, kalau kosong gambar produknya. Produk hanya dibaca bila relasinya
            // SUDAH dimuat pemanggil (StorefrontCatalogService), tidak pernah lazy-load per varian.
            'image_url' => $images->url($this->image_path)
                ?? ($this->resource->relationLoaded('product') ? $images->url($this->product?->image_path) : null),
            'price' => number_format((float) $this->sell_price, 2, '.', ''),
            'in_stock' => $this->current_stock > 0,
            'max_qty' => $this->storefrontMaxQty(),
        ];
    }
}
