<?php

namespace App\Http\Requests;

use App\Services\ProductCatalogQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 043 — filter katalog produk (mode katalog GET /products?tab=…). Izin lihat tetap lewat ProductPolicy
 * (terbuka untuk pengguna login, seperti sebelumnya); nilai tak valid → 422.
 */
class ProductCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tab' => ['sometimes', 'in:menu,retail'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'array'],
            'category_id.*' => ['integer'],
            'status' => ['sometimes', 'array'],
            'status.*' => ['in:draft,active,discontinued'],
            'quality' => ['sometimes', 'nullable', 'in:'.implode(',', ProductCatalogQuery::QUALITY_KINDS)],
            'availability' => ['sometimes', 'nullable', 'in:sold_out'],
            // 045 Stage 3 — hanya item yang DIBAWA salah satu outlet ini (dipotong dengan outlet yang boleh dilihat pengguna).
            'outlet_id' => ['sometimes', 'array'],
            'outlet_id.*' => ['integer'],
            'sort' => ['sometimes', 'in:name,-name,sku,-sku,price,-price,status,-status'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
