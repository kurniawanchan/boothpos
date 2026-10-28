<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 079 — filter dan halaman daftar terminal (halaman Terminal di web). Hanya owner/admin, sama dengan rute lainnya.
 * `outlet_id[]` hanya MENYEMPITKAN (OutletContext::narrowedOrNull); nilai di luar outlet pengguna tidak pernah memperluas.
 */
class DeviceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isOwnerOrAdmin();
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['sometimes', 'array'],
            'outlet_id.*' => ['nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:active,revoked,all'],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
