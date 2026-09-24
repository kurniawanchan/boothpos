<?php

namespace App\Http\Requests;

use App\Support\Couriers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 022-preorder-invoice-crud-overhaul (US1, FR-001) — mirip
 * StorePreorderRequest, tapi SEMUA field opsional (`sometimes`) kecuali
 * `items`, karena edit tidak wajib mengganti setiap field sekaligus.
 * Validasi status (ditolak jika handed_over/cancelled) dan perhitungan
 * ulang stok terjadi di PreorderService::update(), bukan di sini —
 * FormRequest hanya memvalidasi BENTUK data.
 */
class UpdatePreorderRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'event_id' => ['sometimes', 'nullable', 'integer', 'exists:events,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'fulfillment' => ['sometimes', 'in:pickup,courier'],
            'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'pickup_day' => ['sometimes', 'nullable', 'date'],
            'courier_name' => ['sometimes', 'nullable', 'string', Rule::in(Couriers::OPTIONS)],
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
