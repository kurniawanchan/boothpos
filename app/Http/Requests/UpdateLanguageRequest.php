<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sengaja TIDAK memakai UserPolicy — mengganti bahasa adalah preferensi
 * personal, bukan hak akses berbasis menu. Kasir tanpa canAccessMenu('users')
 * tetap HARUS bisa mengganti bahasanya sendiri (FR-003/FR-004,
 * 002-language-toggle research.md Decision 4). authorize() karena itu
 * selalu true — satu-satunya batasan adalah "harus sudah login", yang
 * sudah ditegakkan middleware auth:sanctum di routes/api.php.
 */
class UpdateLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'language' => ['required', 'string', Rule::in(['id', 'en'])],
        ];
    }
}
