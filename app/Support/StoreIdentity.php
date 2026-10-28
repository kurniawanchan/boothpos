<?php

namespace App\Support;

use App\Models\Outlet;
use App\Models\Setting;

/**
 * 079 — satu-satunya tempat yang memilih blok identitas toko (nama, alamat, telepon, surel, catatan) yang
 * ditampilkan ke pelanggan atau staf. Dulu struk membaca `store_*` dari pengaturan bersama dan sidebar membaca
 * `StoreBranding`; sekarang identitas mengikuti outlet, jadi dua jalur baca akan saling menyimpang.
 *
 * Dua aturan sengaja berbeda:
 *  - STRUK (`forReceipt`): milik outlet tempat penjualan terjadi, tidak pernah outlet yang sedang dipilih penonton.
 *    Detail outlet yang kosong DIHILANGKAN (null) — tidak diisi dari pengaturan bersama, supaya satu outlet tidak
 *    pernah mencetak telepon toko lain. Satu pengecualian: outlet yang alamat, telepon, surel, dan catatannya
 *    SEMUA kosong memakai seluruh blok bersama (termasuk nama), sehingga instalasi satu outlet yang belum
 *    mengisi apa pun mencetak struk yang sama persis dengan sebelum fitur ini (SC-007).
 *  - LAYAR (`forScreen`): outlet terpilih, atau pengaturan bersama bila "Semua outlet"; detail kosong jatuh ke
 *    nilai bersama per kolom (FR-013).
 *
 * Logo, teks footer, nama merek, dan "kontak person" tetap bersama dan TIDAK lewat sini.
 */
class StoreIdentity
{
    /**
     * `contact` = baris kontak tunggal lama (`store_contact`) yang masih dikirim ke klien lama.
     *
     * @return array{name: ?string, address: ?string, phone: ?string, email: ?string, notes: ?string, contact: ?string}
     */
    public static function forReceipt(?Outlet $outlet): array
    {
        $own = $outlet === null ? [] : self::ownDetails($outlet);

        if (array_filter($own) === []) {
            $shared = self::shared();

            return $shared + ['contact' => self::clean(Setting::get('store_contact'))];
        }

        return ['name' => self::clean($outlet->name)] + $own + ['contact' => $own['phone']];
    }

    /** @return array{name: ?string, address: ?string, phone: ?string, email: ?string, notes: ?string} */
    public static function forScreen(?Outlet $outlet): array
    {
        $shared = self::shared();
        if ($outlet === null) {
            return $shared;
        }

        $own = self::ownDetails($outlet);

        return [
            'name' => self::clean($outlet->name) ?? $shared['name'],
            'address' => $own['address'] ?? $shared['address'],
            'phone' => $own['phone'] ?? $shared['phone'],
            'email' => $own['email'] ?? $shared['email'],
            'notes' => $own['notes'] ?? $shared['notes'],
        ];
    }

    /** @return array{address: ?string, phone: ?string, email: ?string, notes: ?string} */
    private static function ownDetails(Outlet $outlet): array
    {
        return [
            'address' => self::clean($outlet->address),
            'phone' => self::clean($outlet->contact_phone),
            'email' => self::clean($outlet->contact_email),
            'notes' => self::clean($outlet->notes),
        ];
    }

    /** @return array{name: ?string, address: ?string, phone: ?string, email: ?string, notes: ?string} */
    private static function shared(): array
    {
        return [
            'name' => self::clean(StoreBranding::storeName()),
            'address' => self::clean(Setting::get('store_address')),
            'phone' => self::clean(Setting::get('store_contact_phone')),
            'email' => self::clean(Setting::get('store_contact_email')),
            'notes' => null,
        ];
    }

    private static function clean(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === null || $text === '' ? null : $text;
    }
}
