<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US6 / 048 S3 — body `DELETE /tabs/{id}/items/{item}`. Baris yang BELUM dikirim tidak membutuhkan apa-apa.
 * Baris yang SUDAH dikirim wajib `reason_code`, `kitchen_status` (sama dengan status dapur sebenarnya), dan
 * `stock_decision`; aturan itu ditegakkan di TabService::removeLine (422). `override_token` dibaca dari BODY
 * (DELETE membawa body JSON); tidak dari query.
 */
class CancelTabLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
            'reason_code' => ['nullable', 'string', 'in:wrong_item,customer_request,kitchen_error,other'],
            'kitchen_status' => ['nullable', 'string', 'in:not_started,started,made'],
            'stock_decision' => ['nullable', 'string', 'in:return,waste'],
            'override_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
