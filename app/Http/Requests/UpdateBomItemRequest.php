<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 034-seller-po-bom — satu-satunya yang boleh diubah dari baris BOM bersumber
 * PO adalah jumlah per unit dan catatan; biaya, vendor, dan sumbernya hanya
 * berubah lewat replace-source (aksi eksplisit).
 */
class UpdateBomItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty_needed' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
