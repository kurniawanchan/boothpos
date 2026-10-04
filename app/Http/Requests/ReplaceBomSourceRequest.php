<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 034-seller-po-bom — ganti sumber baris BOM; aturan seller/status ditegakkan VariantBomService. */
class ReplaceBomSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
        ];
    }
}
