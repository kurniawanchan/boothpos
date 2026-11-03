<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 090 / US6 — verifikasi pembayaran pesanan mandiri. Gerbang menu `self_service`; izin QRIS dicek di service. */
class VerifySelfServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('self_service') ?? false;
    }

    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'min:4', 'max:100']];
    }

    public function messages(): array
    {
        return ['reference.min' => __('self_service.reference_min'), 'reference.required' => __('self_service.reference_min')];
    }
}
