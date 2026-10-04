<?php

namespace App\Support;

/**
 * Pembantu pemisahan POS vs pre-order pada laporan (feature 033).
 *
 * Bagian pre-order SENGAJA dihitung sebagai SISA dari total yang sudah
 * tampil (total − bagian POS), bukan dibulatkan sendiri-sendiri. Alasannya:
 * total_units di artist_settlements adalah integer hasil pembulatan
 * (POS bulat + unit pre-order pecahan), dan uang disimpan 2 desimal —
 * membulatkan kedua bagian secara terpisah bisa membuat POS + pre-order
 * berbeda 1 unit / 1 sen dari total (mis. dua pre-order 0,5 unit → 1 + 1 ≠
 * round(1,0) = 1). Dengan sisa, penjumlahan SELALU persis sama dengan
 * total tanpa mengubah angka total yang sudah ada (research.md Decision 2).
 *
 * Seluruh hitungan uang dilakukan dalam sen (integer) agar tidak terkena
 * jebakan float (0,1 + 0,2).
 */
final class ReportSplit
{
    /** Nilai uang (angka/string "1234.50") → sen, dibulatkan setengah ke atas. */
    public static function cents(float|int|string|null $value): int
    {
        // round() dua kali: pertama ke 2 desimal supaya 1.005-ish dari
        // SUM() SQL tidak turun ke 100 sen, lalu ke sen utuh.
        return (int) round(round((float) $value, 2) * 100);
    }

    /** Sen → string uang 2 desimal, format yang dipakai semua laporan. */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** total − bagian, dalam sen, sebagai string uang 2 desimal. */
    public static function remainder(float|int|string|null $total, float|int|string|null $part): string
    {
        return self::money(self::cents($total) - self::cents($part));
    }
}
