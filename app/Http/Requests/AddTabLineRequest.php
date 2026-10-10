<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US2 — menambah baris. SENGAJA tidak ada field harga/total: harga selalu
 * dihitung server dari master data (Constitution IV).
 */
class AddTabLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:255'],
            'modifier_option_ids' => ['sometimes', 'array', 'max:50'],
            'modifier_option_ids.*' => ['integer'],
        ];
    }
}
