<?php

namespace App\Http\Requests\Settlement;

use App\Services\ImageUploadService;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Pembayaran settlement. `client_ref` (UUID dari klien) membuat pengiriman
 * ulang aman; batas atas jumlah (sisa terutang) ditegakkan di service dalam
 * transaksi — di sini hanya bentuknya.
 */
class SettlementPaymentRequest extends SettlementRequest
{
    public function rules(): array
    {
        return [
            'client_ref' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', function (string $attribute, mixed $value, Closure $fail) {
                if (! preg_match('/^\d{1,11}(\.\d{1,2})?$/', (string) $value) || (float) $value <= 0) {
                    $fail(__('settlements.amount_positive'));
                }
            }],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'ewallet', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpeg,png', 'max:'.ImageUploadService::MAX_KILOBYTES],
        ];
    }
}
