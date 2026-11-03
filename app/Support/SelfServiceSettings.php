<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 090 / research D6 — SATU pembaca alamat dasar tempat QR meja mengarah (pola PosSettings/AppName).
 *
 * Alamat itu harus bisa dijangkau ponsel pelanggan, jadi `localhost`/loopback ditolak (FR-023): QR yang berisi
 * `http://localhost/...` terlihat benar di layar staf tetapi tidak membuka apa pun di ponsel pelanggan. Tanpa
 * pengaturan, dipakai asal permintaan staf BILA asal itu sendiri bukan loopback.
 */
final class SelfServiceSettings
{
    public const BASE_URL = 'self_service_base_url';

    /** Alamat dasar tersimpan (tanpa garis miring akhir), atau null bila kosong/tidak valid. */
    public static function configuredBaseUrl(): ?string
    {
        $value = Setting::get(self::BASE_URL);

        return is_string($value) && self::isUsable($value) ? rtrim(trim($value), '/') : null;
    }

    /**
     * Alamat dasar yang dipakai membuat QR: pengaturan, selain itu asal permintaan staf (hanya bila bukan loopback).
     * null = belum siap; pemanggil harus menolak pembuatan QR dengan pesan jelas.
     */
    public static function baseUrl(?string $requestOrigin = null): ?string
    {
        $configured = self::configuredBaseUrl();
        if ($configured !== null) {
            return $configured;
        }

        return $requestOrigin !== null && self::isUsable($requestOrigin) ? rtrim(trim($requestOrigin), '/') : null;
    }

    /** URL penuh halaman pesan mandiri untuk satu token, atau null bila alamat dasar belum siap. */
    public static function urlFor(string $token, ?string $requestOrigin = null): ?string
    {
        $base = self::baseUrl($requestOrigin);

        return $base === null ? null : $base.'/pesan/'.$token;
    }

    /** http/https dengan host yang bukan loopback dan bukan `localhost`. */
    public static function isUsable(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // Loopback IPv4 (127.0.0.0/8), 0.0.0.0, dan IPv6 ::1 / ::.
        if (preg_match('/^127\./', $host) === 1 || $host === '0.0.0.0' || $host === '::1' || $host === '::') {
            return false;
        }

        return true;
    }
}
