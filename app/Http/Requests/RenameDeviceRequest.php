<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 064 — ganti nama terminal: hanya owner/admin; aturan nama sama dengan pendaftaran (keunikan di DeviceService). */
class RenameDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isOwnerOrAdmin();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80']];
    }
}
