<?php

namespace App\Http\Requests\Customer;

use App\Rules\PasswordWithinBcryptLimit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §5) — POST /customer/auth/login.
 *
 * Hanya bentuk yang divalidasi (422). Benar/salahnya kredensial diputuskan
 * CustomerAccountService::authenticate() dengan galat generik 401. Batas
 * panjang (karakter dan 72 byte, lihat PasswordWithinBcryptLimit) mencegah
 * masukan raksasa sampai ke pemeriksaan hash.
 */
class LoginRequest extends FormRequest
{
    /** Rute publik (dibatasi limiter `customer-login`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:72', new PasswordWithinBcryptLimit],
        ];
    }
}
