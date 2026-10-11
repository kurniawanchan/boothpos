<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('variant')->product) ?? false;
    }

    public function rules(): array
    {
        return [
            'variant_name' => ['sometimes', 'string', 'max:100'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'sell_price' => ['sometimes', 'numeric', 'min:0'],
            // 043 — tanggal berlaku harga (kosong = seketika / rilis berikutnya); tidak boleh di masa lalu.
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
        ];
    }
}
