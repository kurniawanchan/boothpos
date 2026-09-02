<?php

namespace App\Http\Requests;

use App\Support\MenuKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ini HANYA gerbang menu ('roles' di menu_keys) → 403. Guard FR-013
        // (perubahan menu_keys yang akan menghilangkan kemampuan mengelola
        // pengguna & peran dari seluruh instalasi) BUKAN soal hak akses,
        // melainkan konflik aturan bisnis — dicek manual di
        // RoleController::update() dan dijawab 409, konsisten dengan pola
        // UpdateUserRequest/UserController untuk guard FR-006.
        return $this->user()?->can('update', $this->route('role')) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('update', $this->route('role'));

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('roles', 'name')->whereNull('deleted_at')->ignore($role?->id),
            ],
            'menu_keys' => ['sometimes', 'required', 'array'],
            'menu_keys.*' => [Rule::in(MenuKeys::keys())],
        ];
    }

    public function messages(): array
    {
        return [
            'menu_keys.*.in' => 'Kunci menu tidak dikenal.',
        ];
    }
}
