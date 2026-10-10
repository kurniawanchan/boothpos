<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US2 — pembayaran tab (sebagian atau penuh). Aturan `payments.*`
 * identik dengan StoreOrderRequest; `client_ref` WAJIB agar pengiriman ulang
 * tidak mencatat pembayaran dua kali (FR-010).
 */
class TabPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_ref' => ['required', 'uuid'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,bank_transfer,qr_ewallet'],
            'payments.*.channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.proof_token' => ['nullable', 'uuid'],
            'payments.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
