<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 048 / T060 — body `POST /orders/{id}/refund`. Alasan wajib; token wajib (diperiksa di RefundService). */
class RefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('sales') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', \Illuminate\Validation\Rule::exists('reason_codes', 'code')->where('category', 'refund')],
            'reason' => ['nullable', 'string', 'max:200'],
            'override_token' => ['nullable', 'string', 'max:120'],
        ];
    }
}
