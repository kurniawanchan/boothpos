package com.warkop.pos.ui

/**
 * 065 / research D9 — berapa baris pecahan per baris layar. Dulu buka shift SELALU dua per baris, sehingga di panel
 * kiri tablet label pecahan ("Rp 100.000") terjepit di antara stepper dan subtotal dan terpotong ("Rp 100."). Kini
 * kolom dihitung dari lebar yang TERSEDIA: satu sel butuh [MIN_CELL_DP] (label ≥ [LABEL_MIN_DP] + stepper + subtotal);
 * lebar kurang dari dua sel → satu pecahan per baris. Murni, supaya aturannya diuji tanpa perangkat.
 */
object DenominationLayout {
    /** Lebar terkecil satu sel pecahan: label penuh + stepper (~140 dp) + subtotal (~88 dp) + sela. */
    const val MIN_CELL_DP = 420f

    /** Lebar minimum label agar "Rp 100.000" dan "Koin Rp 500" tidak pernah terpotong. */
    const val LABEL_MIN_DP = 124f

    /** Banyak kolom untuk lebar [widthDp], dibatasi 1..[maxColumns]. */
    fun columnsFor(widthDp: Float, minCellDp: Float = MIN_CELL_DP, maxColumns: Int = 3): Int =
        (widthDp / minCellDp).toInt().coerceIn(1, maxColumns)
}
