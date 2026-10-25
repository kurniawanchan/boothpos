<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 073 — daftar/pilih terminal dari tablet yang belum login. Tanpa pengguna, jadi `authorize()` terbuka (keputusan
 * pemilik: pendaftaran terbuka, spec Q1); pembatasnya throttle di rute dan data yang hanya berupa nama.
 * Outlet dari path rute, tidak pernah dari body. Keunikan nama dijaga DeviceService, bukan rule `unique` di sini.
 */
class RegisterTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
