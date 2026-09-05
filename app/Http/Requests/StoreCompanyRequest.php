<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('create', Company::class);

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        return [
            // Hanya business type/package yang masih aktif boleh dipilih
            // untuk company BARU (data-model.md's validation rules) —
            // company yang sudah ada tetap menampilkan rujukan lamanya
            // meski business type/package itu belakangan dinonaktifkan.
            'business_type_id' => [
                'required', 'integer',
                Rule::exists('business_types', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'package_id' => [
                'required', 'integer',
                Rule::exists('packages', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'contact_name' => ['required', 'string', 'max:100'],
            'contact_email' => ['required', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            // owner_username/password memakai rule yang sama dengan
            // StoreUserRequest — akun ini adalah User sungguhan di tabel
            // yang sama, tunduk keunikan global yang sama (FR-002).
            'owner_username' => [
                'required', 'string', 'max:50',
                Rule::unique('users', 'username')->whereNull('deleted_at'),
            ],
            'owner_password' => ['required', 'string', 'min:8'],
        ];
    }
}
