<?php

namespace App\Http\Requests;

use App\Services\OrderReferenceService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 054 (US8) — bentuk saja untuk PUT /orders/{order}/reference.
 * Izin (owner/admin atau kasir penjualannya), batas gambar yang SUDAH tersimpan (409) dan aturan "hasil tak
 * boleh kosong" dinilai di OrderReferenceService setelah baris dikunci; pemeriksaan isi berkas (bukan
 * sekadar ekstensi) juga di sana.
 *
 * 055 — multipart `note?`, `images[]?` (gambar yang DITAMBAHKAN), `remove_image_ids[]?` (id yang dihapus).
 * Batasnya dibaca dari konstanta service supaya validasi bentuk dan aturan bisnis tak bisa berbeda angka.
 */
class UpsertOrderReferenceRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array', 'max:'.OrderReferenceService::MAX_IMAGES],
            'images.*' => ['file', 'mimes:jpeg,png,webp', 'max:'.OrderReferenceService::MAX_IMAGE_KILOBYTES],
            'remove_image_ids' => ['nullable', 'array'],
            'remove_image_ids.*' => ['integer'],
        ];
    }
}
