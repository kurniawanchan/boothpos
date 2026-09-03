<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 003-seed-demo-live — satu-satunya tempat yang tahu mode DEMO/LIVE mana
 * yang sedang aktif. Pola persis sama dengan LicenseGate: satu baris
 * `settings` (`system_mode`) sebagai sumber kebenaran, dibaca lewat
 * method statis supaya tidak ada `Setting::get('system_mode', ...)` yang
 * tersebar di banyak tempat berbeda.
 */
class ModeGate
{
    public const DEMO = 'demo';

    public const LIVE = 'live';

    /**
     * Stack override, dipakai ModeGate::runAs() (mis. oleh
     * SakanaFridgeDemoSeeder) supaya penulisan data bisa dipaksa ke satu
     * mode TANPA bergantung pada `system_mode` yang sedang tersimpan, dan
     * TANPA perlu mengubah signature service manapun (OrderService,
     * PreorderService, StockService tetap tidak tahu apa-apa soal
     * DEMO/LIVE — lihat research.md Decision 2).
     *
     * @var list<string>
     */
    private static array $overrideStack = [];

    /**
     * Default 'live' bila baris `system_mode` belum pernah dibuat —
     * instalasi baru TIDAK PERNAH diam-diam mulai dalam mode DEMO.
     */
    public static function current(): string
    {
        if (self::$overrideStack !== []) {
            return end(self::$overrideStack);
        }

        return Setting::get('system_mode', self::LIVE);
    }

    public static function isDemo(): bool
    {
        return self::current() === self::DEMO;
    }

    public static function isLive(): bool
    {
        return self::current() === self::LIVE;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function runAs(string $mode, callable $callback): mixed
    {
        self::$overrideStack[] = $mode;

        try {
            return $callback();
        } finally {
            array_pop(self::$overrideStack);
        }
    }
}
