<?php

namespace App\Support;

/**
 * 028 — bentuk tampilan snapshot modifier pada baris order. Snapshot tersimpan
 * lengkap (termasuk id grup/opsi untuk penjagaan hapus & penggabungan baris),
 * tetapi API/struk hanya menampilkan nama dan selisih harga.
 */
class ModifierSnapshot
{
    /**
     * @param  array<int, array<string, mixed>>|null  $snapshot
     * @return array<int, array{group: string, option: string, price_delta: string}>
     */
    public static function forDisplay(?array $snapshot): array
    {
        return array_values(array_map(fn (array $m) => [
            'group' => (string) ($m['group'] ?? ''),
            'option' => (string) ($m['option'] ?? ''),
            'price_delta' => number_format((float) ($m['price_delta'] ?? 0), 2, '.', ''),
        ], $snapshot ?? []));
    }
}
