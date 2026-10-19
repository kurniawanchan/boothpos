<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Support\ServerAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 054 / US3 — validasi PUT /settings/server-address. Otorisasi memakai SettingPolicy::update, sama dengan PUT /settings,
 * supaya aturan "siapa boleh mengubah pengaturan" hanya ada di satu tempat (FR-011).
 */
class UpdateServerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Setting::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'local' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'online' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'selected' => ['required', 'string', 'in:'.ServerAddress::LOCAL.','.ServerAddress::ONLINE],
        ];
    }

    public function messages(): array
    {
        return [
            'local.url' => 'Alamat server lokal harus berupa URL http atau https yang lengkap.',
            'online.url' => 'Alamat server online harus berupa URL http atau https yang lengkap.',
            'selected.in' => 'Pilihan server harus lokal atau online.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Memilih online tanpa alamat online akan membuat tablet kehilangan server; ditolak di sini, bukan di tablet.
        $validator->after(function (Validator $validator): void {
            if ($this->input('selected') === ServerAddress::ONLINE && blank($this->input('online'))) {
                $validator->errors()->add('selected', 'Pilih online hanya jika alamat online sudah diisi.');
            }
        });
    }
}
