<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return [
            ...\App\Support\OutletCarriedRule::rules(),
            // code_prefix dan product_segment TIDAK ada di sini secara
            // sengaja (bukan 'prohibited' seperti Outlet/Category, karena
            // OpenAPI ProductInput tidak mensyaratkan 'prohibited' untuk
            // field ini — kolom yang tidak dikirim cukup diabaikan oleh
            // FormRequest, tidak perlu ditolak keras). Konsisten dengan
            // catatan di openapi-pos-mvp.yaml: "Perubahan nama tidak
            // mengubah code_prefix maupun SKU yang sudah ada."
            'home_outlet_id' => ['sometimes', 'integer', 'exists:outlets,id'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_preorder' => ['sometimes', 'boolean'],
            // 043 — fakta item (tidak dibatasi rilis: berlaku seketika).
            'short_name' => ['sometimes', 'nullable', 'string', 'max:24'],
            'tax_category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600'],
            'is_retail' => ['sometimes', 'boolean'],
            'preorder_eta' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'station_id' => ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! \App\Models\Station::whereKey($value)->exists()) {
                    $fail(__('restaurant.station_not_found'));
                }
            }],
        ];
    }
}
