<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 048 / S3 — body `POST /tabs/{id}/cancel`: alasan kode wajib, catatan opsional, token persetujuan opsional. */
class CancelTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'in:wrong_item,customer_request,kitchen_error,other'],
            'reason' => ['nullable', 'string', 'max:255'],
            'override_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
