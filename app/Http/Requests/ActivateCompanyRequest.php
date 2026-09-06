<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activate', $this->route('company')) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
        ];
    }
}
