<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Customer commerce (CONTRACT §5) — kata sandi pelanggan paling banyak 72 BYTE.
 *
 * bcrypt hanya membaca 72 byte pertama. Aturan `max:72` menghitung KARAKTER,
 * jadi sandi 72 huruf beraksen (144 byte) lolos dan dua sandi yang sama di 72
 * byte pertamanya akan sama-sama dianggap benar. Aturan ini menolak sandi yang
 * lebih dari 72 byte (`strlen`, bukan `mb_strlen`) di pendaftaran DAN login;
 * `max:72` tetap dipasang berdampingan sebagai batas karakter.
 *
 * Pesannya kalimat siap tampil (bukan kunci mentah) karena langsung dibaca
 * pelanggan di aplikasi.
 */
class PasswordWithinBcryptLimit implements ValidationRule
{
    public const MAX_BYTES = 72;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAX_BYTES) {
            $fail(__('customer_commerce.account.password_too_long', ['max' => self::MAX_BYTES]));
        }
    }
}
