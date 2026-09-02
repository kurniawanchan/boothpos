<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ini HANYA gerbang menu ('users' di menu_keys) → 403. Guard
        // FR-006 (tidak boleh menonaktifkan/mengganti peran diri sendiri)
        // sengaja TIDAK dilempar di sini — itu bukan soal hak akses
        // (permission problem) melainkan konflik aturan bisnis, jadi
        // dicek manual di UserController::update() dan dijawab 409,
        // konsisten dengan pola ArtistController/CategoryController untuk
        // guard bisnis lain di kodebase ini.
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    protected function failedAuthorization(): void
    {
        $response = Gate::inspect('update', $this->route('user'));

        throw new AuthorizationException($response->message() ?: 'Tidak berhak.');
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'username' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('users', 'username')->whereNull('deleted_at')->ignore($user?->id),
            ],
            // Password hanya diubah bila dikirim — kosong berarti "biarkan
            // tetap sama", sama seperti pola blank-cell impor massal.
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role_id' => [
                'sometimes', 'required',
                Rule::exists('roles', 'id')->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
