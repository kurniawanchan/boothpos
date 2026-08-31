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
            // code_prefix dan product_segment TIDAK ada di sini secara
            // sengaja (bukan 'prohibited' seperti Artist/Category, karena
            // OpenAPI ProductInput tidak mensyaratkan 'prohibited' untuk
            // field ini — kolom yang tidak dikirim cukup diabaikan oleh
            // FormRequest, tidak perlu ditolak keras). Konsisten dengan
            // catatan di openapi-pos-mvp.yaml: "Perubahan nama tidak
            // mengubah code_prefix maupun SKU yang sudah ada."
            'artist_id' => ['sometimes', 'integer', 'exists:artists,id'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_preorder' => ['sometimes', 'boolean'],
            'preorder_eta' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
