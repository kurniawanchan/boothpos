<?php

namespace App\Support;

/**
 * 043-products-page-redesign (research D12) — SATU-SATUNYA rumus biaya resep. Murni, tanpa I/O.
 *
 *   biaya baris   = jumlah ÷ (1 − susut%/100) × biaya satuan
 *   biaya porsi   = Σ biaya baris ÷ yield
 *   food cost %   = biaya porsi ÷ harga dasar × 100 (satu desimal)
 *   margin kotor  = harga dasar − biaya porsi
 *
 * Baris tanpa biaya satuan TIDAK dihitung sebagai nol: hasilnya `incomplete = true` dan food cost % /
 * margin menjadi null, supaya angka yang kurang tidak tampak seperti angka yang benar.
 */
class RecipeCost
{
    public static function line(float $quantity, float $shrinkagePct, ?float $unitCost): ?float
    {
        if ($unitCost === null) {
            return null;
        }

        return round($quantity / (1 - $shrinkagePct / 100) * $unitCost, 4);
    }

    /**
     * @param  list<array{quantity: float|int|string, shrinkage_pct: float|int|string, unit_cost: float|int|string|null}>  $lines
     * @return array{line_costs: list<float|null>, portion_cost: float, incomplete: bool, food_cost_pct: float|null, gross_margin: float|null}
     */
    public static function compute(array $lines, float $yield, ?float $basePrice): array
    {
        $costs = [];
        $incomplete = false;
        $sum = 0.0;

        foreach ($lines as $l) {
            $cost = self::line((float) $l['quantity'], (float) $l['shrinkage_pct'], $l['unit_cost'] === null ? null : (float) $l['unit_cost']);
            $costs[] = $cost;
            $cost === null ? $incomplete = true : $sum += $cost;
        }

        $portion = $yield > 0 ? round($sum / $yield, 4) : 0.0;
        $priced = ! $incomplete && $basePrice !== null && $basePrice > 0;

        return [
            'line_costs' => $costs,
            'portion_cost' => $portion,
            'incomplete' => $incomplete,
            'food_cost_pct' => $priced ? round($portion / $basePrice * 100, 1) : null,
            'gross_margin' => $priced ? round($basePrice - $portion, 2) : null,
        ];
    }
}
