<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 054 / US3 — alamat server lokal dan online yang dipilih admin. SATU-SATUNYA pembaca dan penulis tiga baris
 * settings ini (constitution I): controller dan API perangkat tidak boleh membaca `settings` langsung.
 *
 * Kedua alamat adalah dua jalur ke SATU server dan SATU database (constitution, Stack & Environment Constraints).
 * Pilihan hanya mengubah jalur jaringan, bukan data (research.md R4).
 */
final class ServerAddress
{
    public const KEY_LOCAL = 'server_address_local';

    public const KEY_ONLINE = 'server_address_online';

    public const KEY_SELECTED = 'server_address_selected';

    public const LOCAL = 'local';

    public const ONLINE = 'online';

    /** @return array{local: ?string, online: ?string, selected: string} */
    public static function current(): array
    {
        return [
            'local' => self::clean(Setting::get(self::KEY_LOCAL)),
            'online' => self::clean(Setting::get(self::KEY_ONLINE)),
            'selected' => Setting::get(self::KEY_SELECTED, self::LOCAL) === self::ONLINE ? self::ONLINE : self::LOCAL,
        ];
    }

    /**
     * Alamat untuk pilihan yang aktif. null = tablet tetap memakai alamat pairing-nya (research.md R3, opsi A).
     */
    public static function selectedUrl(): ?string
    {
        $current = self::current();

        return $current[$current['selected']];
    }

    /**
     * @param  array{local?: ?string, online?: ?string, selected: string}  $values
     */
    public static function save(array $values): void
    {
        $rows = [
            self::KEY_LOCAL => self::clean($values['local'] ?? null),
            self::KEY_ONLINE => self::clean($values['online'] ?? null),
            self::KEY_SELECTED => $values['selected'] === self::ONLINE ? self::ONLINE : self::LOCAL,
        ];

        foreach ($rows as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => 'string', 'group' => 'server']);
        }
    }

    /** Spasi dan garis miring di akhir dibuang; kosong berarti tidak diisi. */
    private static function clean(mixed $value): ?string
    {
        $trimmed = is_string($value) ? rtrim(trim($value), '/') : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
