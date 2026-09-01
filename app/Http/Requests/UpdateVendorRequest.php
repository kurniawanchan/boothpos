<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('vendor')) ?? false;
    }

    public function rules(): array
    {
        return [
            // code TIDAK bisa diubah lewat update — konsisten dengan
            // Artist/Category (kode adalah identitas stabil untuk referensi
            // Excel, mengubahnya diam-diam merusak baris impor lama).
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'email', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
