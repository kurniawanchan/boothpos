<?php

namespace App\Http\Requests;

use App\Support\Couriers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePreorderRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'fulfillment' => ['required', 'in:pickup,courier'],
            'shipping_cost' => ['sometimes', 'numeric', 'min:0'],
            // 021-preorder-form-updates (US2) — nominal Rupiah tetap
            // (bukan persen — research.md Decision 3). Batas atas (tidak
            // boleh > subtotal+shipping) divalidasi di PreorderService,
            // bukan di sini, karena butuh subtotal hasil hitung item.
            'discount' => ['sometimes', 'numeric', 'min:0'],
            // 021-preorder-form-updates (US3) — bentuk saja yang dicek di
            // sini (tanggal valid / termasuk daftar kurir dikenal).
            // Kecocokan dengan event/fulfillment (FR-008a/FR-014) perlu
            // subtotal & data event, jadi divalidasi di PreorderService.
            'pickup_day' => ['nullable', 'date'],
            'courier_name' => ['nullable', 'string', Rule::in(Couriers::OPTIONS)],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
