<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('material')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'unit' => ['sometimes', 'required', 'string', 'max:20'],
            // 043 (Q2 = B) — jenis bahan dan biaya standar (untuk bahan setengah jadi tanpa harga vendor).
            'kind' => ['sometimes', 'in:ingredient,semi_finished,packaging'],
            'standard_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
