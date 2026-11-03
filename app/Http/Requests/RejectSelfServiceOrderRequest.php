<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 090 / US6 — tolak pesanan mandiri; alasan wajib. Gerbang menu `self_service`; izin QRIS dicek di service. */
class RejectSelfServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('self_service') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['reason.required' => __('self_service.reject_reason_required')];
    }
}
