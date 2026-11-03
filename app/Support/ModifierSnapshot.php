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
     * @return array<int, array{group: string, option: string, qty: int, label: string, price_delta: string}>
     */
    public static function forDisplay(?array $snapshot): array
    {
        // 089: `qty` (baris lama tanpa kunci = 1) dan `label` "Nama ×N" bila lebih dari satu; `price_delta` = kontribusi total.
        return array_values(array_map(function (array $m) {
            $qty = max(1, (int) ($m['qty'] ?? 1));
            $option = (string) ($m['option'] ?? '');

            return [
                'group' => (string) ($m['group'] ?? ''),
                'option' => $option,
                'qty' => $qty,
                'label' => $qty > 1 ? "{$option} ×{$qty}" : $option,
                'price_delta' => number_format((float) ($m['price_delta'] ?? 0), 2, '.', ''),
            ];
        }, $snapshot ?? []));
    }
}
