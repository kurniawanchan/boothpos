<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('license')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'license_tier' => ['sometimes', 'required', Rule::in(['pro', 'master'])],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'payment_type' => ['sometimes', 'required', Rule::in(['one_time', 'subscription'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
