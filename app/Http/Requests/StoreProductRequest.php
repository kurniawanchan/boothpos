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
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'product_segment' => ['nullable', 'string', 'size:3', 'alpha'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_preorder' => ['sometimes', 'boolean'],
            'preorder_eta' => ['nullable', 'date', 'required_if:is_preorder,true'],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.variant_name' => ['required', 'string', 'max:100'],
            'variants.*.cost_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.sell_price' => ['required', 'numeric', 'min:0'],
            'variants.*.low_stock_alert' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
