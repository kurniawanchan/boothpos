<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 043 — membuat kode alasan baru; hanya menu `settings` (admin). */
class StoreReasonCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:30', 'regex:/^[a-z_]+$/'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('reason_codes', 'code')->where('category', $this->input('category'))],
            'label_id' => ['required', 'string', 'max:120'],
            'label_en' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
