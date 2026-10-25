<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 074 — owner/admin mengatur PIN pengguna. Aturan sama dengan `pin-reset`: hanya yang bisa membuka Pengaturan
 * (`isOwnerOrAdmin`); punya menu Pengguna saja tidak cukup. Penegakannya di server — menyembunyikan tombol di UI hanya kosmetik.
 * Aturan PIN lemah ada di PinAuthService (satu definisi), bukan di sini.
 */
class SetUserPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isOwnerOrAdmin();
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:6'],
            // Dilaporkan klien, hanya penanda di log aktivitas (tidak dapat dibuktikan server).
            'source' => ['nullable', 'in:typed,generated'],
        ];
    }
}
