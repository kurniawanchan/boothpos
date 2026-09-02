<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attach harga vendor untuk satu bahan (POST /materials/{material}/vendor-prices).
 * Digerbang canManageMasterData() langsung, mengikuti pola
 * ImportMasterDataRequest/StockAdjustmentRequest: ini master data
 * relasional, bukan satu model tunggal yang punya Policy sendiri secara
 * alami untuk aksi attach seperti ini.
 */
class StoreVendorMaterialPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('materials') ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Hanya owner/admin/inventory yang dapat mengelola harga vendor.');
    }

    public function rules(): array
    {
        $materialId = $this->route('material')->id;

        return [
            'vendor_id' => [
                'required', 'integer', 'exists:vendors,id',
                Rule::unique('vendor_material_prices', 'vendor_id')->where('material_id', $materialId),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'is_preferred' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.unique' => 'Vendor ini sudah punya harga untuk bahan ini. Ubah harganya lewat endpoint update.',
        ];
    }
}
