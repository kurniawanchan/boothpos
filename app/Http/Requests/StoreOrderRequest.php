<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Semua peran yang login boleh bertransaksi — ini memang tugas
        // utama kasir. Tidak ada pembatasan peran tambahan di sini.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'integer', 'exists:cashier_sessions,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'local_ref' => ['required', 'uuid'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.discount_amount' => ['sometimes', 'numeric', 'min:0'],

            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,bank_transfer,qr_ewallet'],
            'payments.*.channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
            'payments.*.purpose' => ['sometimes', 'in:full,down_payment,settlement'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.proof_token' => ['nullable', 'uuid'],
            'payments.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
