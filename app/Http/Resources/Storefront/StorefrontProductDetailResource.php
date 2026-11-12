<?php

namespace App\Http\Resources\Storefront;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Customer commerce (CONTRACT §6) — detail produk etalase: kartu + deskripsi
 * + varian yang ditawarkan. Mewarisi kartu ETALASE (bukan resource staf)
 * supaya kartu dan detail tidak pernah berbeda isi untuk produk yang sama.
 *
 * `variants` berisi persis yang dimuat StorefrontCatalogService::findBySlug()
 * (terbit, aktif, berstok); resource ini tidak menyaring ulang.
 *
 * @mixin Product
 */
class StorefrontProductDetailResource extends StorefrontProductCardResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'variants' => StorefrontVariantResource::collection($this->variants),
        ];
    }
}
