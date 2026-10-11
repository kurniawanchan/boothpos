<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 043-products-page-redesign — pembacaan pengaturan menu di satu tempat (pola yang sama dengan
 * BusinessDay/AppName): rilis menu aktif atau tidak, ambang persetujuan harga, pembulatan bawaan.
 * `menu_release_enabled` default FALSE supaya instalasi yang ada berperilaku persis seperti sebelumnya
 * (edit langsung berlaku; research.md D2).
 */
class MenuSettings
{
    public const DEFAULT_THRESHOLD_PCT = 15.0;

    public static function releaseEnabled(): bool
    {
        return filter_var(Setting::get('menu_release_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Selisih (% dari harga dasar) di atas mana perubahan harga butuh persetujuan. */
    public static function approvalThresholdPct(): float
    {
        $v = Setting::get('price_approval_threshold_pct', self::DEFAULT_THRESHOLD_PCT);

        return is_numeric($v) && (float) $v >= 0 && (float) $v <= 100 ? (float) $v : self::DEFAULT_THRESHOLD_PCT;
    }

    /** `none` atau `500`. */
    public static function roundingDefault(): string
    {
        $v = (string) Setting::get('price_rounding_default', '500');

        return in_array($v, ['none', '500'], true) ? $v : '500';
    }
}
