<?php

namespace App\Http\Requests;

use App\Models\BusinessType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreBusinessTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BusinessType::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', BusinessType::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('business_types', 'code')->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
