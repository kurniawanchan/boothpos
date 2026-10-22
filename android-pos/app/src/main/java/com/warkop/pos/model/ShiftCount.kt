package com.warkop.pos.model

/**
 * 065 — uang yang dihitung kasir untuk buka/tutup shift: per pecahan ATAU satu total ketikan (rupiah utuh).
 * Yang dikirim ke server selalu SATU dari keduanya; server tidak pernah menerima campuran (spec FR-014).
 */
sealed interface ShiftCount {
    val total: Int

    data class ByDenomination(val counts: Map<Int, Int>) : ShiftCount {
        /** Hanya pecahan yang berjumlah > 0, urut sebagaimana diisi. */
        val rows: List<Pair<Int, Int>> get() = counts.filter { it.value > 0 }.map { it.key to it.value }
        override val total: Int get() = rows.sumOf { it.first * it.second }
    }

    data class Typed(override val total: Int) : ShiftCount
}
