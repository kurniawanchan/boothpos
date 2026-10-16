<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\ProductSegment;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * Segmen dinormalisasi (spasi dibuang, huruf besar) SEBELUM diperiksa, supaya
     * aturan memeriksa bentuk yang akan disimpan. Segmen kosong tetap kosong →
     * diturunkan dari nama produk (spec 042).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('product_segment')) {
            $this->merge(['product_segment' => ProductSegment::normalise($this->input('product_segment'))]);
        }
    }

    public function messages(): array
    {
        return [
            'product_segment.regex' => 'Segmen produk harus 1 sampai 3 huruf (A–Z).',
        ];
    }

    public function rules(): array
    {
        return [
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'product_segment' => ['nullable', 'string', 'regex:'.ProductSegment::PATTERN_RULE],
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
