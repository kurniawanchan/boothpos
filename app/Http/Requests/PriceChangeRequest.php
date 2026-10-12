<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 043 — pratinjau/penerapan ubah harga massal: izin `menu.price.bulk`. Validasi isi dilakukan BulkPriceService
 * (satu tempat, galat per bidang) karena sama untuk pratinjau dan penerapan.
 */
class PriceChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(PermissionCatalog::PRICE_BULK) ?? false;
    }

    public function rules(): array
    {
        return [
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer'],
            'type' => ['sometimes', 'nullable', 'string'],
            'value' => ['sometimes', 'nullable'],
            'rounding' => ['sometimes', 'nullable', 'string'],
            'effective_from' => ['sometimes', 'nullable', 'string'],
            'price_scope' => ['sometimes', 'nullable', 'string'],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer'],
        ];
    }
}
