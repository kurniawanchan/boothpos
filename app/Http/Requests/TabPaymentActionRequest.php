<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 048 / S3 — body verifikasi/penandaan QRIS tab: referensi (≥ 4 dicek di PaymentService) dan token persetujuan. */
class TabPaymentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'min:4', 'max:40'],
            'override_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
