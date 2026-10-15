<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028-partial-split-payment — bentuk saja, dipakai bersama pre-order dan
 * penjualan POS. Aturan bisnis (sisa tagihan, status tertutup, shift kasir,
 * idempotensi) dinilai di PaymentService setelah baris target dikunci.
 *
 * `client_ref` opsional di server (klien lama/skrip tanpa kunci tetap jalan),
 * tetapi SPA selalu mengirimnya; bila ada, wajib UUID.
 */
class StorePaymentRequest extends FormRequest
{
    // Sama seperti checkout kasir: semua peran terautentikasi boleh mencatat pembayaran.
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,bank_transfer,qr_ewallet'],
            'channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
            'purpose' => ['sometimes', 'in:full,down_payment,settlement'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'proof_token' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_ref' => ['nullable', 'uuid'],
        ];
    }
}
