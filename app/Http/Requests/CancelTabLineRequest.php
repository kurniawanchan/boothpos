<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 028 US6 — membatalkan/menghapus baris tab. `reason` opsional di sini karena baris yang BELUM dikirim
 * dihapus bebas; untuk baris yang sudah dikirim, TabService menolak alasan kosong (422). Izin untuk
 * membatalkan item yang sudah dimasak (owner/admin) diperiksa di service, per status dapur item itu.
 */
class CancelTabLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:255']];
    }
}
