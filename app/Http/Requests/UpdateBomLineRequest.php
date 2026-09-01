<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBomLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageMasterData() ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Hanya owner/admin/inventory yang dapat mengelola BOM.');
    }

    public function rules(): array
    {
        return [
            'qty_needed' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
