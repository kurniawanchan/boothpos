<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US5 — pisah tagihan. `payments.*` identik dengan TabPaymentRequest; pembayaran HARUS
 * menuntaskan penjualan terpisah itu dalam satu panggilan (tidak ada pembayaran sebagian di sini).
 */
class SplitTabRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,bank_transfer,qr_ewallet'],
            'payments.*.channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.proof_token' => ['nullable', 'uuid'],
            'payments.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
