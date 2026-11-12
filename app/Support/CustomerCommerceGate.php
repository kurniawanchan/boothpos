<?php

namespace App\Support;

use App\Models\CustomerOrder;
use App\Models\Setting;

/**
 * Customer commerce (CONTRACT §2) — SATU-satunya tempat yang membaca
 * pengaturan fitur toko online (pola LicenseGate / ModeGate / AppName).
 *
 * Kenapa harus lewat sini: Setting::get() meng-cache nilai BAWAAN pemanggil
 * pertama selamanya bila barisnya belum ada, jadi dua pemanggil dengan
 * bawaan berbeda untuk kunci yang sama bisa saling menyimpang. Semua kunci
 * `customer_commerce_*` karenanya hanya dibaca di kelas ini.
 *
 * Saklarnya satu untuk seluruh instalasi (bukan per mode DEMO/LIVE, seperti
 * `app_name`) dan TIDAK punya baris bawaan: baris yang tidak ada berarti
 * MATI, sehingga instalasi lama tidak mendadak membuka etalase publik
 * setelah diperbarui.
 */
class CustomerCommerceGate
{
    public const KEY = 'customer_commerce_enabled';

    public const PICKUP_INSTRUCTIONS_KEY = 'customer_commerce_pickup_instructions';

    public const COURIER_INSTRUCTIONS_KEY = 'customer_commerce_courier_instructions';

    /** Kunci teks petunjuk (divalidasi sebagai teks biasa di UpdateSettingsRequest). */
    public const INSTRUCTION_KEYS = [self::PICKUP_INSTRUCTIONS_KEY, self::COURIER_INSTRUCTIONS_KEY];

    public const INSTRUCTIONS_MAX_LENGTH = 500;

    /**
     * filter_var, bukan (bool): nilai tersimpan sebagai teks dan
     * (bool) "false" bernilai TRUE di PHP (lihat LicenseGate).
     */
    public static function enabled(): bool
    {
        return filter_var(Setting::get(self::KEY, false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function pickupInstructions(): ?string
    {
        return self::text(self::PICKUP_INSTRUCTIONS_KEY);
    }

    public static function courierInstructions(): ?string
    {
        return self::text(self::COURIER_INSTRUCTIONS_KEY);
    }

    /** Petunjuk untuk satu metode pemenuhan pesanan (`pickup` / `courier`). */
    public static function instructionsFor(string $fulfilmentMethod): ?string
    {
        return $fulfilmentMethod === CustomerOrder::FULFILMENT_COURIER
            ? self::courierInstructions()
            : self::pickupInstructions();
    }

    /**
     * Nama toko yang dilihat pelanggan: kunci yang sama dengan
     * GET /settings/features (berbeda per mode DEMO/LIVE). Kosong = merek
     * aplikasi, supaya kolomnya selalu berisi teks.
     */
    public static function storeName(): string
    {
        $name = trim((string) Setting::get(ModeGate::isDemo() ? 'store_name_demo' : 'store_name'));

        return $name !== '' ? $name : AppName::current();
    }

    /** Teks kosong / hanya spasi diperlakukan sebagai "tidak ada petunjuk". */
    private static function text(string $key): ?string
    {
        $value = trim((string) Setting::get($key));

        return $value !== '' ? $value : null;
    }
}
