<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('stock') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            // 045 Stage 5 — stok adalah saldo per outlet. Wajib bila pengguna melihat beberapa outlet (diperiksa di
            // StockController); satu outlet = outlet itu.
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.qty_change' => ['required', 'integer', 'not_in:0'],
        ];
    }
}
