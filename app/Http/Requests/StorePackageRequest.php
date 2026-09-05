<?php

namespace App\Http\Requests;

use App\Models\Package;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Package::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', Package::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'license_tier' => ['required', Rule::in(['pro', 'master'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
