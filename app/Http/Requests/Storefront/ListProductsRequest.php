<?php

namespace App\Http\Requests\Storefront;

use App\Services\CustomerCommerce\StorefrontCatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer commerce (CONTRACT §6) — parameter GET /storefront/products.
 *
 * Rute ini publik, jadi setiap nilai dibatasi di sini: panjang kata cari,
 * daftar urutan yang diizinkan, dan angka halaman. Parameter yang tidak
 * dikenal diabaikan (validated() tidak memuatnya); nilai yang salah bentuk
 * ditolak 422 `validation_failed` lewat amplop problem+json.
 *
 * `per_page` di atas batas TIDAK ditolak: dipangkas di
 * StorefrontCatalogService, sama seperti daftar staf.
 */
class ListProductsRequest extends FormRequest
{
    /** Etalase publik: tidak ada pengguna untuk diotorisasi. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', Rule::in(StorefrontCatalogService::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.StorefrontCatalogService::MAX_PAGE],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
