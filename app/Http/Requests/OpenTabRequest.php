<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 028 US2 — membuka tab. Gerbang: menu `floor` (juga diperiksa ulang di service). */
class OpenTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('floor') ?? false;
    }

    public function rules(): array
    {
        return [
            // Rujukan dicari lewat Eloquent di service (patuh DataModeScope → 404
            // lintas-mode); `exists:` bawaan memakai query mentah yang melewatinya.
            'session_id' => ['required', 'integer'],
            'table_id' => ['nullable', 'integer'],
            'tab_label' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer'],
            'local_ref' => ['nullable', 'uuid'],
            // 048 — jumlah tamu (pax). Perangkat TIDAK dari body: dibaca dari header X-Device-Id dan dicek milik outlet pengguna.
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
