<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 048 / T058 — body `POST /tabs/{id}/discounts`. Persen wajib (0 < p ≤ 100, paling banyak dua desimal). */
class DiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'percent' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            // Kode dari kategori `discount` (migrasi 2026_12_24_000002), sama dengan daftar yang dibaca aplikasi.
            'reason_code' => ['required', 'string', \Illuminate\Validation\Rule::exists('reason_codes', 'code')->where('category', 'discount')],
            'scope' => ['required', 'in:order,line'],
            'line_id' => ['required_if:scope,line', 'nullable', 'integer'],
            'override_token' => ['nullable', 'string', 'max:120'],
        ];
    }
}
