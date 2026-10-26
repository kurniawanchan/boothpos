<?php

namespace App\Http\Requests\Settlement;

use Closure;

/**
 * Potongan (deduction) diketik sebagai angka POSITIF — service yang
 * menyimpannya negatif. Penyesuaian (adjustment) boleh positif/negatif,
 * tidak boleh nol. Dua desimal paling banyak, muat di decimal(14,2).
 */
class AdjustmentRequest extends SettlementRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:deduction,adjustment'],
            'label' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:2000'],
            'basis' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', function (string $attribute, mixed $value, Closure $fail) {
                if (! preg_match('/^-?\d{1,11}(\.\d{1,2})?$/', (string) $value)) {
                    $fail(__('settlements.amount_invalid'));

                    return;
                }
                if ((float) $value === 0.0) {
                    $fail(__('settlements.amount_zero'));

                    return;
                }
                if ($this->input('kind') === 'deduction' && (float) $value < 0) {
                    $fail(__('settlements.amount_deduction_positive'));
                }
            }],
        ];
    }
}
