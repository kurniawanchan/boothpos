<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBomLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        // BOM digantung pada varian produk (lihat CLAUDE.md "Vendor,
        // material, dan BOM tracking") — dipetakan ke menu 'products'.
        return $this->user()?->canAccessMenu('products') ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Hanya owner/admin/inventory yang dapat mengelola BOM.');
    }

    public function rules(): array
    {
        $variantId = $this->route('variant')->id;

        return [
            'material_id' => [
                'required', 'integer', 'exists:materials,id',
                Rule::unique('product_variant_bom_lines', 'material_id')->where('product_variant_id', $variantId),
            ],
            'qty_needed' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'material_id.unique' => __('vendors_materials.bom_line_already_exists'),
        ];
    }
}
