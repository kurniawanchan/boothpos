<?php

namespace App\Support;

use App\Models\Artist;
use App\Models\Setting;

/**
 * Satu-satunya tempat yang tahu perbedaan Pro vs Master. Kalau nanti ada
 * flag berbayar lain selain multi-artist, tambahkan method di sini —
 * jangan sebar pengecekan Setting::get('multi_artist_enabled') di banyak
 * tempat berbeda.
 */
class LicenseGate
{
    /**
     * TIDAK memakai (bool) cast langsung — di PHP, (bool)"false" bernilai
     * TRUE karena string non-kosong dianggap truthy (hanya "0" dan ""
     * yang falsy). filter_var dengan FILTER_VALIDATE_BOOLEAN menangani
     * "true"/"false"/"1"/"0" dengan benar.
     */
    public static function multiArtistEnabled(): bool
    {
        $value = Setting::get('multi_artist_enabled', false);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function activeArtistCount(): int
    {
        return Artist::where('is_active', true)->count();
    }

    /**
     * Aturan: Pro hanya boleh punya SATU artist aktif (bawaan/dirinya
     * sendiri). Instalasi baru yang belum punya artist sama sekali tetap
     * boleh membuat satu, baik Pro maupun Master.
     */
    public static function canCreateArtist(): bool
    {
        if (self::multiArtistEnabled()) {
            return true;
        }

        return self::activeArtistCount() === 0;
    }

    public static function artistLimitReached(): bool
    {
        return ! self::multiArtistEnabled() && self::activeArtistCount() >= 1;
    }
}
