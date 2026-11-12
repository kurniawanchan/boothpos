<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerAccount;
use App\Services\CustomerCommerce\StorefrontCatalogService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §8) — parameter GET /customer/orders.
 *
 * Aturan halaman SAMA dengan katalog etalase supaya aplikasi hanya mengenal
 * satu konvensi: bawaan 20, `per_page` di atas batas dipangkas (bukan
 * ditolak), nilai di bawah 1 / bukan bilangan bulat ditolak 422.
 */
class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer') instanceof CustomerAccount;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:'.StorefrontCatalogService::MAX_PAGE],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
