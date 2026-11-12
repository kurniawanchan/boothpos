<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Customer commerce — teks yang diketik STAF tetapi dibaca PELANGGAN di
 * aplikasi (catatan linimasa pesanan, alasan pembatalan).
 *
 * Harus teks biasa: tanpa bentuk tag HTML dan tanpa karakter kontrol selain
 * baris baru/tab, supaya isinya tidak pernah bisa ditafsirkan sebagai markup
 * oleh klien mana pun (aplikasi Android hari ini, klien lain kelak). Tanda
 * `<` atau `&` lepas ("stok < 5") tetap boleh; yang ditolak adalah bentuk tag
 * (`<b>`, `</a>`, `<script …>`). Aturannya sama dengan teks petunjuk toko di
 * UpdateSettingsRequest::isShortPlainText(), ditambah karakter format yang
 * tak terlihat: spasi lebar nol dan penanda arah baca (U+200B–U+200F,
 * U+202A–U+202E, U+2066–U+2069) serta BOM (U+FEFF). Teks staf dibaca pelanggan
 * di aplikasi; karakter arah baca bisa membalik tampilan nomor resi atau
 * tautan ("Trojan Source"), dan karakter lebar nol membuat dua teks yang
 * tampak sama berbeda. (Akibatnya U+200D/U+200C, perekat urutan emoji dan
 * aksara tertentu, juga ditolak: teks staf memang harus sederhana.)
 *
 * Panjang dan wajib/tidaknya diatur aturan lain di pemanggil (`max:255`,
 * `required`/`nullable`); aturan non-implisit ini tidak dijalankan untuk
 * nilai kosong.
 */
class CustomerVisiblePlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            // `/u` gagal (false) pada UTF-8 rusak: nilai seperti itu juga ditolak.
            || preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $value) !== 0
            || preg_match('/<\s*\/?\s*[a-zA-Z!][^>]*>/', $value) === 1) {
            $fail('customer_commerce.fulfilment.plain_text')->translate();
        }
    }
}
