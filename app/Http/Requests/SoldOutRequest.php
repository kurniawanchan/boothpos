<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

/** 043 — menandai item habis (86): izin `menu.item.86`, alasan wajib. */
class SoldOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 048 / S3 — otorisasi sebenarnya di OverrideGate (izin menu.availability.set atau token persetujuan).
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            ...\App\Support\OutletCarriedRule::rules(),
            'reason_code_id' => ['required', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            // 048 / S3 — tanpa menu.availability.set, tanda habis butuh persetujuan (diperiksa OverrideGate).
            'override_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
