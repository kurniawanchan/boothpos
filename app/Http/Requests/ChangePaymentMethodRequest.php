<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 054 (US7) — bentuk saja untuk PATCH /orders/{order}/payments/{payment}/method. Kecocokan metode
 * dengan jenis kanal, kanal aktif, siapa boleh, dan aturan shift/kembalian dinilai di
 * PaymentService setelah baris dikunci (bergantung pada isi yang tersimpan).
 */
class ChangePaymentMethodRequest extends FormRequest
{
    // Per-objek (Payment::mayManageConfirmation), bukan per-peran.
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['cash', 'qr_ewallet', 'bank_transfer'])],
            'channel_id' => ['nullable', 'integer', 'exists:payment_channels,id'],
        ];
    }
}
