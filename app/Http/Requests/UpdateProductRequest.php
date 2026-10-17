<?php

namespace App\Http\Requests;

use App\Support\ProductSegment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /**
     * Segmen dinormalisasi sebelum diperiksa (spec 044). Kosong = tidak diubah;
     * segmen tidak diturunkan dari nama saat edit, karena itu akan diam-diam
     * mengubah kode produk setiap kali nama berubah.
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
            'product_segment.regex' => 'Segmen produk harus 1 sampai 6 karakter (huruf A–Z, boleh tanda hubung tunggal di antara huruf).',
        ];
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
            // Spec 044: segmen boleh diubah; server meregenerasi SKU (ProductSegmentChangeService).
            'product_segment' => ['sometimes', 'nullable', 'string', 'regex:'.ProductSegment::PATTERN_RULE],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'is_preorder' => ['sometimes', 'boolean'],
            'preorder_eta' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
