<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 048 / T009 — pengaturan terminal POS di SATU tempat (pola AppName/LicenseGate): kunci, nilai bawaan, dan aturan
 * "kosong/tidak valid = bawaan" tidak tersebar sebagai `Setting::get('...', X)` dengan bawaan yang bisa menyimpang.
 *
 * Bawaan konservatif: pajak 0% (instalasi lama tidak berubah), ambang dan toleransi mengikuti nilai contoh desain
 * (PRD §8.4, menunggu keputusan pemilik produk — lihat research.md D9 dan spec Assumptions).
 */
class PosSettings
{
    public const TAX_RATE = 'tax_rate';

    public const TAX_LABEL = 'tax_label';

    public const CASH_OUT_THRESHOLD = 'cash_out_approval_threshold';

    public const VARIANCE_TOLERANCE = 'variance_tolerance';

    public const EDC_DUPLICATE_WINDOW = 'edc_duplicate_window_seconds';

    public const REPRINT_FREE = 'reprint_free_copies';

    /** Tarif pajak eksklusif dalam persen (0–100, maks 2 desimal). */
    public static function taxRate(): float
    {
        $v = Setting::get(self::TAX_RATE);

        return is_numeric($v) && $v >= 0 && $v <= 100 ? round((float) $v, 2) : 0.0;
    }

    public static function taxLabel(): string
    {
        $v = trim((string) Setting::get(self::TAX_LABEL));

        return $v !== '' ? mb_substr($v, 0, 30) : 'Pajak';
    }

    public static function cashOutApprovalThreshold(): int
    {
        return self::intOr(self::CASH_OUT_THRESHOLD, 200_000);
    }

    public static function varianceTolerance(): int
    {
        return self::intOr(self::VARIANCE_TOLERANCE, 5_000);
    }

    public static function edcDuplicateWindowSeconds(): int
    {
        return self::intOr(self::EDC_DUPLICATE_WINDOW, 120);
    }

    public static function reprintFreeCopies(): int
    {
        return self::intOr(self::REPRINT_FREE, 1);
    }

    private static function intOr(string $key, int $default): int
    {
        $v = Setting::get($key);

        return is_numeric($v) && $v >= 0 ? (int) $v : $default;
    }
}
