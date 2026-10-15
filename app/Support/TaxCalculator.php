<?php

namespace App\Support;

/**
 * 048 / T026 — SATU rumus pajak eksklusif (research D9). Pajak dihitung SETELAH diskon, atas dasar pengenaan satu
 * order, dibulatkan ke rupiah penuh (HALF_UP) per ORDER — bukan per baris — supaya tablet, struk, dan laporan
 * menjumlah ke angka yang sama. Tarif dari PosSettings (0% bawaan → instalasi lama tidak berubah).
 */
final class TaxCalculator
{
    /** @return array{tax: float, rate: float} */
    public static function compute(float $taxableBase, ?float $rate = null): array
    {
        $rate ??= PosSettings::taxRate();
        $base = max(0.0, $taxableBase);

        return ['tax' => (float) round($base * $rate / 100, 0, PHP_ROUND_HALF_UP), 'rate' => $rate];
    }
}
