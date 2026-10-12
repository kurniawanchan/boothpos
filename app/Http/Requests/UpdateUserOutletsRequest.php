<?php

namespace App\Http\Requests;

use App\Models\Outlet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 045 Stage 2 — PUT /users/{user}/outlets. Otorisasi peran ada di UserPolicy::update (controller),
 * jadi di sini cukup validasi bentuk dan keberadaan outlet.
 */
class UpdateUserOutletsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outlet_ids' => ['present', 'array'],
            // Outlet::query() memakai DataModeScope, jadi outlet mode lain otomatis tidak dikenali.
            'outlet_ids.*' => [
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! Outlet::query()->whereKey($value)->where('is_active', true)->exists()) {
                        $fail(__('outlets.unknown_outlet'));
                    }
                },
            ],
        ];
    }
}
