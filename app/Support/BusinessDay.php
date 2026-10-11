<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * 042-dashboard-revamp — SATU-satunya tempat yang menjawab "tanggal bisnis berapa instan ini?"
 * dan "rentang UTC berapa untuk tanggal bisnis ini?".
 *
 * Mengapa ada: `config('app.timezone')` di proyek ini tetap `UTC` dan semua `created_at` disimpan
 * UTC, sehingga hari WIB bergeser 7 jam bila dihitung dari `DATE(created_at)` (penjualan pukul
 * 02.00 WIB jatuh ke tanggal UTC sebelumnya). Zona waktu toko dan jam batas hari (cut-off) kini
 * pengaturan; tanggal bisnis DISIMPAN pada baris saat dibuat/diselesaikan (orders, payments,
 * cashier_sessions) supaya mengubah zona/cut-off kemudian tidak menggeser riwayat (Konstitusi IV).
 *
 * Tahap B memindahkan zona waktu dan cut-off ke baris outlet; pemanggil tidak perlu berubah
 * karena hanya kelas ini yang membaca sumbernya.
 */
class BusinessDay
{
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    public const DEFAULT_CUTOFF = '00:00';

    public static function timezone(): string
    {
        $tz = (string) Setting::get('store_timezone', self::DEFAULT_TIMEZONE);

        return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : self::DEFAULT_TIMEZONE;
    }

    /** Jam batas hari "HH:MM" — hari bisnis baru dimulai pada jam ini (waktu lokal toko). */
    public static function cutoff(): string
    {
        $cutoff = (string) Setting::get('business_day_cutoff', self::DEFAULT_CUTOFF);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff) ? $cutoff : self::DEFAULT_CUTOFF;
    }

    /** Tanggal bisnis (Y-m-d) tempat sebuah instan jatuh. */
    public static function dateFor(CarbonInterface $instant): string
    {
        [$h, $m] = array_map('intval', explode(':', self::cutoff()));

        return CarbonImmutable::instance($instant)
            ->setTimezone(self::timezone())
            ->subHours($h)->subMinutes($m)
            ->toDateString();
    }

    /** Awal hari bisnis `$date` sebagai instan UTC. */
    public static function startOf(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.self::cutoff().':00', self::timezone())->utc();
    }

    /**
     * Rentang setengah-terbuka [mulai, akhir) dalam UTC. Akhir = awal hari bisnis berikutnya,
     * dihitung dari tanggal kalender (bukan +24 jam) supaya zona ber-DST tetap benar.
     */
    public static function bounds(string $date): array
    {
        $next = CarbonImmutable::parse($date, self::timezone())->addDay()->toDateString();

        return [self::startOf($date), self::startOf($next)];
    }

    public static function today(): string
    {
        return self::dateFor(CarbonImmutable::now());
    }
}
