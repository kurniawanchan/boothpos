<?php

namespace App\Support;

/**
 * 090 / US4 — SATU-SATUNYA penormal nomor telepon Indonesia untuk mencocokkan pelanggan pesan mandiri dengan data
 * pelanggan yang sudah ada. Bentuk kanonik: angka saja, diawali `0` (`+62 812-3456-7890`, `6281234567890`, `081234567890`
 * → `081234567890`). Nomor tersimpan lama (impor/ketikan kasir) tidak pernah ditulis ulang — hanya dibandingkan lewat
 * bentuk kanoniknya.
 */
final class PhoneNormalizer
{
    public const MIN_DIGITS = 8;

    public const MAX_DIGITS = 15;

    /** @return string|null bentuk kanonik, atau null bila bukan nomor telepon yang masuk akal */
    public static function canonical(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS ? $digits : null;
    }

    /** Sebagian nomor untuk ditampilkan di daftar staf: `0812****7890`. Pendek/tak valid disamarkan seluruhnya. */
    public static function masked(?string $raw): ?string
    {
        $canonical = self::canonical($raw);
        if ($canonical === null) {
            return $raw === null || $raw === '' ? null : '••••';
        }

        return substr($canonical, 0, 4).str_repeat('*', max(0, strlen($canonical) - 8)).substr($canonical, -4);
    }
}
