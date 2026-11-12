<?php

namespace App\Http\Requests\CustomerOrder;

use App\Models\CustomerOrder;
use App\Services\CustomerCommerce\StorefrontCatalogService;
use Illuminate\Validation\Rule;

/**
 * Customer commerce (CONTRACT §9) — penyaring GET /customer-orders (staf).
 * Konvensi halaman daftar staf: `per_page` 1–100, bawaan 25. `page` dibatasi
 * batas yang sama dengan daftar pelanggan (StorefrontCatalogService::MAX_PAGE):
 * tanpa batas atas, `page` sebesar bilangan bulat membuat OFFSET melampaui
 * jangkauan dan basis data menjawab 500.
 */
class ListCustomerOrdersRequest extends CustomerOrderFulfilmentRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(CustomerOrder::STATUSES)],
            'fulfilment_method' => ['nullable', Rule::in(CustomerOrder::FULFILMENT_METHODS)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.StorefrontCatalogService::MAX_PAGE],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
