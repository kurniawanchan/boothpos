<?php

namespace App\Support;

/**
 * Pembantu hitungan uang dalam sen (integer) — dipakai laporan dan perhitungan biaya BOM.
 *
 * 046: pemisahan POS vs pre-order (feature 033) sudah hilang bersama modul Pre-order; yang tersisa hanya
 * konversi sen ↔ string uang 2 desimal, supaya penjumlahan tidak terkena jebakan float (0,1 + 0,2).
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
