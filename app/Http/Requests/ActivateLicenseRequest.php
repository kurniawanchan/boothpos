<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivateLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Sengaja tanpa auth:sanctum — endpoint ini justru harus bisa
        // diakses SAAT instalasi belum ter-aktivasi, jadi belum ada user
        // yang bisa login sama sekali (FR-002).
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string'],
        ];
    }
}
