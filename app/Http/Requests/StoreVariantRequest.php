<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return [
            'variant_name' => ['required', 'string', 'max:100'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            // 034-seller-po-bom — salin BOM dari varian lain produk yang sama
            // saat varian dibuat (diperiksa ulang oleh VariantBomService::copy).
            'copy_bom_from_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ];
    }
}
