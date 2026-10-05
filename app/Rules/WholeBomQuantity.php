<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 036-bom-variant-stock-ux — jumlah bahan/jasa per SATU unit produk jadi
 * adalah bilangan bulat >= 1 (kunci rantai 11, bukan 11,0000 atau 1,5).
 *
 * Satu-satunya definisi aturan ini; dipakai semua pintu masuk (ubah jumlah,
 * simpan massal, tambah baris PO, jalur legacy). Sengaja BUKAN aturan
 * `integer` Laravel: ia menolak "11.0000" dan 2.0 — persis bentuk yang
 * dikembalikan basis data dan sel Excel — padahal nilainya bilangan bulat.
 * Nilai kosong ditangani aturan `required`/`nullable` di pemanggil (aturan
 * non-implisit tidak dijalankan untuk nilai kosong).
 *
 * Data lama yang pecahan TIDAK ditulis ulang; aturan ini hanya berlaku saat
 * sebuah jumlah ditulis (research.md D3).
 */
class WholeBomQuantity implements ValidationRule
{
    public const MAX = 99999999;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = is_string($value) ? trim($value) : $value;

        if (! is_numeric($text)) {
            $fail('bom.qty_whole')->translate();

            return;
        }

        $number = (float) $text;

        if ($number < 1 || $number > self::MAX || floor($number) !== $number) {
            $fail('bom.qty_whole')->translate();
        }
    }
}
