<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 054 (US8) — bentuk saja untuk PUT /orders/{order}/reference (multipart: note?, image?, remove_image?).
 * Izin (owner/admin atau kasir penjualannya) dan aturan "hasil tak boleh kosong" dinilai di
 * OrderReferenceService setelah baris dikunci; pemeriksaan isi berkas (bukan sekadar ekstensi) juga di sana.
 */
class UpsertOrderReferenceRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }
}
