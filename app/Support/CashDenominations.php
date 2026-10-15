<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * 048 / S2 — pecahan uang untuk hitungan buta kasir (kas awal dan kas saat tutup).
 *
 * Satu-satunya tempat daftar pecahan dan penjumlahannya: jumlah kas dihitung SERVER dari
 * pecahan × qty, bukan dipercaya dari klien. Pecahan di luar daftar ditolak, dan pecahan yang
 * sama tidak boleh muncul dua kali (kalau tidak, satu lembar bisa terhitung dua kali).
 */
final class CashDenominations
{
    /** Uang kertas dan logam rupiah yang beredar (emisi 2016 dan 2022). */
    public const VALUES = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    /** Batas kewajaran satu pecahan; mencegah angka salah ketik membengkakkan total. */
    public const MAX_QTY = 100000;

    /**
     * @param  array<int, array{denomination: int|string, qty: int|string}>  $rows
     * @return array{rows: array<int, array{denomination: int, qty: int}>, total: float}
     */
    public static function normalise(array $rows, string $field = 'counted_by_denomination'): array
    {
        $seen = [];
        $clean = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $denomination = (int) $row['denomination'];
            $qty = (int) $row['qty'];

            if (isset($seen[$denomination])) {
                throw ValidationException::withMessages([$field => __('events_sessions.denomination_duplicate', ['value' => number_format($denomination, 0, ',', '.')])]);
            }
            $seen[$denomination] = true;

            $clean[] = ['denomination' => $denomination, 'qty' => $qty];
            $total += $denomination * $qty;
        }

        return ['rows' => $clean, 'total' => round($total, 2)];
    }
}
