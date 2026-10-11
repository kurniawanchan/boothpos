<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 043 — laci Varian & harga: izin ubah produk (ProductPolicy::update, menu products); nama dan harga wajib. */
class VariantBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return [
            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*.id' => ['sometimes', 'nullable', 'integer'],
            'variants.*.variant_name' => ['required', 'string', 'max:100'],
            'variants.*.sell_price' => ['required', 'numeric', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
