<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * 019-billing-system (second expansion, research.md R11) — kelas terpisah
 * dari StoreCompanyRequest, BUKAN reuse: mengedit Company tidak pernah
 * menyentuh kredensial login owner_username/owner_password (itu murni
 * urusan pembuatan akun 017, ortogonal dari data bisnis/kontak company).
 */
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('update', $this->route('company'));

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            'business_type_id' => [
                'required', 'integer',
                Rule::exists('business_types', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'license_id' => [
                'required', 'integer',
                Rule::exists('licenses', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'contact_name' => ['required', 'string', 'max:100'],
            'contact_email' => ['required', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
