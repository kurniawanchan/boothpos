<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('business_type')) ?? false;
    }

    public function rules(): array
    {
        return [
            // code TIDAK bisa diubah lewat update, konsisten dengan
            // Vendor/Artist/Category — kode adalah identitas stabil.
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
