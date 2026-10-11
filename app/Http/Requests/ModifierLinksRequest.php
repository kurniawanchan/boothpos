<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 043 — PUT /products/{id}/modifier-links: izin ubah produk (ProductPolicy::update). Aturan isi dicek ModifierLinkService. */
class ModifierLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    public function rules(): array
    {
        return [
            'links' => ['present', 'array', 'max:30'],
            'links.*.group_id' => ['required', 'integer'],
            'links.*.is_required' => ['sometimes', 'boolean'],
            'links.*.min_select' => ['sometimes', 'nullable', 'integer'],
            'links.*.max_select' => ['sometimes', 'nullable', 'integer'],
            'links.*.display_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
