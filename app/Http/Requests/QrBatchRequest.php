<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 090 / US2 — pembuatan QR meja massal: persis SATU dari `ids` (meja terpilih) atau `filter` (semua hasil filter daftar).
 * Gerbang: menu `dining_setup` (sama dengan mengelola meja). Server tetap menyempitkan ke outlet yang boleh dilihat pengguna.
 */
class QrBatchRequest extends FormRequest
{
    public const MAX = 200;

    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('dining_setup') ?? false;
    }

    public function rules(): array
    {
        return [
            // Persis salah satu dari `ids`/`filter` dicek di withValidator(): `filter: []` (tanpa kriteria = semua meja) dianggap
            // "tidak ada" oleh aturan required_without/prohibits bawaan Laravel, padahal itu permintaan yang sah.
            'ids' => ['sometimes', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer'],
            'filter' => ['sometimes', 'array'],
            'filter.q' => ['nullable', 'string', 'max:60'],
            'filter.area' => ['nullable', 'string', 'max:60'],
            'filter.is_active' => ['nullable', 'boolean'],
            'filter.outlet_id' => ['nullable', 'array'],
            'filter.outlet_id.*' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($this->has('ids') === $this->has('filter')) {
                $v->errors()->add('ids', __('self_service.qr_batch_one_of'));
            }
        });
    }
}
