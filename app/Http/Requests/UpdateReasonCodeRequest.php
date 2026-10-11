<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 043 — mengubah label/urutan/status kode alasan; kategori dan kode tidak berubah (riwayat memakainya). */
class UpdateReasonCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'label_id' => ['sometimes', 'required', 'string', 'max:120'],
            'label_en' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ];
    }
}
