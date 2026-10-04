<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 031-optional-payment-proof — bentuk saja, dipakai bersama penjualan POS dan
 * pre-order untuk PATCH …/payments/{payment}/confirmation. Semua kunci opsional
 * (minimal satu harus dikirim, dinilai di PaymentService karena "kosong" bergantung
 * pada isi yang sudah tersimpan). Aturan bisnis — siapa boleh, transaksi tertutup,
 * tunai, hasil tak boleh kosong — ada di PaymentService setelah baris target dikunci;
 * pelaku dijaga controller (403) dan diulang di service.
 */
class UpdatePaymentConfirmationRequest extends FormRequest
{
    // Semua peran terautentikasi lolos di sini; siapa yang BOLEH mengubah pembayaran
    // tertentu adalah aturan per-objek (Payment::mayManageConfirmation), bukan per-peran.
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'proof_token' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
