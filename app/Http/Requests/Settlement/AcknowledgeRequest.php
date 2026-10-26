<?php

namespace App\Http\Requests\Settlement;

use Illuminate\Validation\Rule;

/**
 * Tiga cara mengakui: tanda tangan gambar (PNG ≤ 512 KB), nama diketik, atau
 * dicatat di luar sistem (wajib catatan). Tipe berkas diperiksa lagi dari
 * ISI-nya di service (lapis kedua).
 */
class AcknowledgeRequest extends SettlementRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['signature', 'typed', 'external'])],
            'signer_name' => ['required_if:method,signature,typed', 'nullable', 'string', 'max:120'],
            'note' => ['required_if:method,external', 'nullable', 'string', 'max:255'],
            'signature' => ['required_if:method,signature', 'nullable', 'file', 'mimes:png', 'max:512'],
        ];
    }
}
