<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorMaterialPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageMasterData() ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Hanya owner/admin/inventory yang dapat mengelola harga vendor.');
    }

    public function rules(): array
    {
        return [
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999999.99'],
            'is_preferred' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
