<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 048 — daftar terminal: hanya owner/admin. Tidak ada `outlet_id`: outlet ditentukan server. */
class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isOwnerOrAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }
}
