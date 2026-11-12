<?php

namespace App\Support;

/**
 * Customer commerce (CONTRACT §8) — tautan pelacakan kurir yang boleh dilihat
 * pelanggan.
 *
 * Tautan TIDAK PERNAH disimpan dan tidak pernah berasal dari input staf atau
 * klien: ia dibentuk di sini, hanya dari templat di
 * config('customer_commerce.tracking_url_templates') yang dikunci per nama
 * kurir. Templat yang tidak diawali `https://` atau tidak memuat
 * `{tracking_number}` diabaikan, dan nomor resi selalu di-URL-encode — jadi
 * nomor resi tidak bisa mengubah host, skema, atau jalur tautan.
 *
 * Daftar templat bawaan KOSONG (belum ada templat kurir yang diverifikasi),
 * sehingga hasilnya selalu null di v1 dan aplikasi tidak menampilkan tombol
 * "Lacak paket".
 */
final class CustomerOrderTrackingUrl
{
    public const PLACEHOLDER = '{tracking_number}';

    public static function for(?string $courierName, ?string $trackingNumber): ?string
    {
        $courierName = trim((string) $courierName);
        $trackingNumber = trim((string) $trackingNumber);

        if ($courierName === '' || $trackingNumber === '') {
            return null;
        }

        $templates = config('customer_commerce.tracking_url_templates');
        $template = is_array($templates) ? ($templates[$courierName] ?? null) : null;

        if (! is_string($template) || ! str_starts_with($template, 'https://') || ! str_contains($template, self::PLACEHOLDER)) {
            return null;
        }

        return str_replace(self::PLACEHOLDER, rawurlencode($trackingNumber), $template);
    }
}
