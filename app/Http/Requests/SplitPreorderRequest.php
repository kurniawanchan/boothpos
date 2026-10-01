<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 027-preorder-duplicate-split — bentuk saja. Kepemilikan baris, batas qty,
 * status, pembayaran, dan batas diskon dinilai di PreorderService::split()
 * karena butuh data pesanan yang terkunci.
 */
class SplitPreorderRequest extends FormRequest
{
    // Sama seperti StorePreorderRequest/UpdatePreorderRequest: semua peran terautentikasi.
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:items,by_seller'],
            'items' => ['required_if:mode,items', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
        ];
    }
}
