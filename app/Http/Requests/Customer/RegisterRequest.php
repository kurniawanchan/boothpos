<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerAccount;
use App\Rules\PasswordWithinBcryptLimit;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §5) — POST /customer/auth/register.
 *
 * SENGAJA tanpa rule `unique:` pada e-mail: rule itu menghasilkan
 * `errors.email` yang memberi tahu siapa pun bahwa sebuah e-mail sudah
 * terdaftar (dan `unique:` melewati scope mode data). Pemeriksaan "sudah
 * terdaftar" ada di CustomerAccountService::register() dan berakhir sebagai
 * galat generik `registration_failed`.
 *
 * Kata sandi maksimal 72 karakter DAN 72 byte: bcrypt memotong masukan di 72
 * byte, jadi yang lebih panjang hanya memberi rasa aman palsu (huruf beraksen
 * memakai lebih dari satu byte; lihat PasswordWithinBcryptLimit).
 *
 * E-mail harus ASCII tercetak (CustomerAccount::isAsciiEmail): kolasi
 * `utf8mb4_unicode_ci` menyamakan `víctim@x.com` dengan `victim@x.com`, yang
 * membuka celah pembatas laju per e-mail dan perebutan alamat orang lain.
 */
class RegisterRequest extends FormRequest
{
    /** Rute publik: siapa pun boleh mencoba mendaftar (dibatasi limiter `customer-register`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && ! CustomerAccount::isAsciiEmail(CustomerAccount::normaliseEmail($value))) {
                    $fail(__('customer_commerce.account.email_ascii'));
                }
            }],
            'password' => ['required', 'string', 'min:8', 'max:72', new PasswordWithinBcryptLimit],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
