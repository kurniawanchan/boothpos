<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 064 — ganti nama terminal; 079 — dan/atau pindah ke outlet lain (`outlet_id`). Hanya owner/admin. Aturan nama sama
 * dengan pendaftaran (keunikan di DeviceService). `outlet_id` hanya DIPERIKSA di controller terhadap outlet yang boleh
 * dilihat pengguna (OutletContext::isPermitted): bentuknya saja yang divalidasi di sini.
 */
class UpdateDeviceRequest extends FormRequest
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
        return [
            'name' => ['required_without:outlet_id', 'string', 'max:80'],
            'outlet_id' => ['required_without:name', 'integer', 'min:1'],
        ];
    }
}
