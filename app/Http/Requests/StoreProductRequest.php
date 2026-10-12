<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            ...\App\Support\OutletCarriedRule::rules(),
            'home_outlet_id' => ['required', 'integer', 'exists:outlets,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'product_segment' => ['nullable', 'string', 'size:3', 'alpha'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_preorder' => ['sometimes', 'boolean'],
            // 043 — fakta item (tidak dibatasi rilis: berlaku seketika).
            'short_name' => ['sometimes', 'nullable', 'string', 'max:24'],
            'tax_category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'is_retail' => ['sometimes', 'boolean'],
            'preorder_eta' => ['nullable', 'date', 'required_if:is_preorder,true'],
            'is_active' => ['sometimes', 'boolean'],
            'station_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! \App\Models\Station::whereKey($value)->exists()) {
                    $fail(__('restaurant.station_not_found'));
                }
            }],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.variant_name' => ['required', 'string', 'max:100'],
            'variants.*.cost_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.sell_price' => ['required', 'numeric', 'min:0'],
            'variants.*.low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'variants.*.track_stock' => ['sometimes', 'boolean'],
        ];
    }
}
