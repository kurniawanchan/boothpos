<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\ImageUploadService;

/**
 * 077 — satu-satunya tempat yang memilih nama toko dan logo toko yang ditampilkan klien. Dulu tiga baris ini hidup di
 * `SettingsController::features` (sidebar web); tablet Android butuh nilai yang SAMA sebelum login
 * (`GET /pos-setup/branding`), jadi dua salinan akan saling menyimpang — salah satunya memilih kunci mode yang keliru.
 *
 * Nama toko berbeda per mode ('store_name_demo' vs 'store_name', lihat 009 FR-019) supaya DEMO tidak menimpa nama LIVE.
 */
class StoreBranding
{
    public static function storeName(): ?string
    {
        return Setting::get(ModeGate::isDemo() ? 'store_name_demo' : 'store_name');
    }

    /** URL diselesaikan server lewat ImageUploadService, konvensi yang sama dengan setiap gambar lain (null = belum ada logo). */
    public static function logoUrl(): ?string
    {
        return app(ImageUploadService::class)->url(Setting::get('store_logo_path'));
    }

    /**
     * Hanya tiga nilai ini yang boleh keluar dari rute tanpa token (pengecualian Konstitusi IV, 077 plan.md): semuanya
     * sudah terlihat oleh setiap peran yang login lewat `GET /settings/features`.
     *
     * @return array{system_mode: string, store_name: ?string, store_logo_url: ?string}
     */
    public static function forTablet(): array
    {
        return [
            'system_mode' => ModeGate::current(),
            'store_name' => self::storeName(),
            'store_logo_url' => self::logoUrl(),
        ];
    }
}
