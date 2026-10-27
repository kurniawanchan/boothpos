<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 077 / US4 — catatan kasir untuk SELURUH order (beda dari catatan per baris, 255 karakter). Batas 1000 sama dengan
 * catatan pembayaran. Hak akses sama dengan mengubah baris tab: menu `floor`. `outlet_id`/`data_mode` tidak pernah dibaca
 * dari badan — hanya `notes`.
 */
class UpdateTabNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['present', 'nullable', 'string', 'max:1000'],
        ];
    }
}
