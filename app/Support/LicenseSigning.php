<?php

namespace App\Support;

/**
 * 018-license-activation — verifikasi tanda tangan Ed25519 murni lokal
 * (research.md R1), memakai ekstensi `sodium` bawaan PHP (sudah ada
 * sejak PHP 7.2, tanpa dependency Composer baru — dikonfirmasi tersedia
 * di `php -m` sebelum fitur ini ditulis).
 *
 * Kunci publik dibaca dari config('license.public_key') (env
 * LICENSE_PUBLIC_KEY), BUKAN konstanta hardcoded — supaya kunci publik
 * produksi sungguhan bisa diset lewat deploy config, dan supaya test
 * bisa mengganti ke kunci publik miliknya SENDIRI (dipasangkan dengan
 * kunci privat yang di-generate sekali pakai saat test jalan, tidak
 * pernah disimpan) tanpa satu pun kunci privat perlu ikut ter-commit ke
 * source control — lihat tests/Feature/LicenseActivationTest.php.
 * Kunci publik AMAN dikomit sebagai default (config/license.php) —
 * verifikasi dengan kunci publik TIDAK BISA dipakai memalsukan lisensi
 * baru. Kunci privat yang berpasangan TIDAK PERNAH ada di repo ini atau
 * di .env manapun yang dikirim ke pelanggan — hanya di environment
 * lokal vendor sendiri, dipakai lewat GenerateLicense (research.md R5).
 */
class LicenseSigning
{
    /**
     * Memverifikasi tanda tangan atas payload MENTAH (belum di-decode).
     * $payloadB64/$signatureB64 keduanya string base64 standar, persis
     * seperti yang disatukan menjadi satu string kunci lisensi oleh
     * GenerateLicense (research.md R2: base64(json).base64(signature)).
     */
    public static function verify(string $payloadB64, string $signatureB64): bool
    {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        $publicKey = base64_decode((string) config('license.public_key'), true);
        if ($publicKey === false) {
            return false;
        }

        // BUG YANG DITEMUKAN & DIPERBAIKI (018-license-activation) —
        // terverifikasi lewat pengujian sungguhan: sodium_crypto_sign_
        // verify_detached() TIDAK mengembalikan false untuk signature
        // yang panjangnya salah (bukan tepat SODIUM_CRYPTO_SIGN_BYTES,
        // 64 byte) — ia melempar SodiumException, yang tanpa try/catch
        // di sini akan meng-crash seluruh request (500) hanya karena
        // seseorang menyalin-tempel kunci lisensi yang rusak/salah
        // format. Ditangkap di sini dan diperlakukan sama seperti tanda
        // tangan tidak valid (false) — konsisten dengan FR-006's prinsip
        // gagal-tertutup diperluas ke jalur validasi kunci ini juga.
        try {
            return sodium_crypto_sign_verify_detached($signature, $payloadB64, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Memecah string kunci lisensi menjadi payload (JSON, sudah
     * ter-decode) dan signature mentah (base64), lalu memverifikasi.
     * Melempar InvalidArgumentException pada KEGAGALAN APA PUN — bentuk
     * salah, JSON tidak valid, field wajib hilang, atau tanda tangan
     * tidak cocok — SEMUANYA dipetakan ke satu pesan generik yang sama
     * oleh pemanggil (FR-003: tidak membocorkan alasan spesifik yang
     * bisa dipakai menebak kunci valid lewat percobaan).
     *
     * @return array{license_id: string, issued_to: string, issued_at: string}
     */
    public static function decodeAndVerify(string $licenseKey): array
    {
        $parts = explode('.', $licenseKey, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Bentuk kunci lisensi tidak valid.');
        }

        [$payloadB64, $signatureB64] = $parts;

        if (! self::verify($payloadB64, $signatureB64)) {
            throw new \InvalidArgumentException('Tanda tangan kunci lisensi tidak valid.');
        }

        $json = base64_decode($payloadB64, true);
        if ($json === false) {
            throw new \InvalidArgumentException('Payload kunci lisensi tidak valid.');
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)
            || ! isset($payload['license_id'], $payload['issued_to'], $payload['issued_at'])
            || ! is_string($payload['license_id'])
            || ! is_string($payload['issued_to'])
            || ! is_string($payload['issued_at'])
        ) {
            throw new \InvalidArgumentException('Field payload kunci lisensi tidak lengkap.');
        }

        return [
            'license_id' => $payload['license_id'],
            'issued_to' => $payload['issued_to'],
            'issued_at' => $payload['issued_at'],
        ];
    }
}
