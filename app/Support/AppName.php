<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Nama aplikasi/merek produk ("BoothPOS") yang bisa diubah owner/admin lewat
 * baris settings `app_name` — dipakai sidebar dan teks "Powered by …" di
 * dokumen (invoice, invoice pembayaran, struk).
 *
 * BUKAN nama toko: `store_name` adalah identitas penjual dan berbeda per mode
 * DEMO/LIVE, sedangkan ini merek produknya dan satu untuk seluruh instalasi.
 *
 * Satu tempat untuk default dan aturan "kosong = default" (pola yang sama
 * dengan LicenseGate/ModeGate), supaya tidak ada `Setting::get('app_name',
 * 'BoothPOS')` tersebar dengan default yang bisa saling menyimpang.
 */
class AppName
{
    public const DEFAULT = 'BoothPOS';

    public const MAX_LENGTH = 50;

    public static function current(): string
    {
        $name = trim((string) Setting::get('app_name'));

        return $name !== '' ? $name : self::DEFAULT;
    }
}
