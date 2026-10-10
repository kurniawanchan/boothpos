<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US2 — mengubah baris yang belum dikirim (jumlah dan/atau catatan). */
class UpdateTabLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'qty' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'modifier_option_ids' => ['sometimes', 'array', 'max:50'],
            'modifier_option_ids.*' => ['integer'],
        ];
    }
}
