<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 048 — daftar terminal: hanya owner/admin. Tidak ada `outlet_id`: outlet ditentukan server.
 * 064 — hanya `name` (dipangkas). Kode terminal tidak lagi diterima; `code` di body tidak ada di rules, jadi tidak ikut `validated()`. Keunikan nama
 * dijaga DeviceService (butuh kunci dan konteks outlet), bukan rule `unique` di sini.
 */
class PairDeviceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
        ];
    }
}
