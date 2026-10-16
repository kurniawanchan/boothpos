<?php

namespace App\Http\Requests;

use App\Models\CashMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 048 / S2 — body `POST /sessions/{id}/cash-movements`. Jumlah harus positif dan hanya dua angka desimal;
 * alasan yang valid bergantung arahnya dan dicek di {@see \App\Services\CashMovementService} (satu sumber:
 * {@see CashMovement::REASONS}). Tidak ada `override_token` di sini: persetujuan dibangun di S3/US4.
 */
class RecordCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in([CashMovement::IN, CashMovement::OUT])],
            'reason_code' => ['required', 'string', 'max:40'],
            'reason_note' => ['nullable', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            // 048 / S3 — persetujuan kas keluar di atas ambang (diperiksa OverrideGate).
            'override_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
