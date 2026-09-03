<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Sengaja TIDAK memakai UserPolicy — sama seperti UpdateLanguageRequest,
 * mengganti password akun sendiri bukan hak akses berbasis menu. Verifikasi
 * current_password dilakukan di AuthController::updatePassword() (butuh
 * $request->user()), bukan di sini.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
