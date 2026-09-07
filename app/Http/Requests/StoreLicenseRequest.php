<?php

namespace App\Http\Requests;

use App\Models\License;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreLicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', License::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', License::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'license_tier' => ['required', Rule::in(['pro', 'master'])],
            'price' => ['required', 'numeric', 'min:0'],
            'payment_type' => ['required', Rule::in(['one_time', 'subscription'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
